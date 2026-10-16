<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Graph ``contact`` <-> vCard 3.0. Port of graphpim/contacts.py.
 *
 * N/FN/NICKNAME, first e-mail PREF, phone types, home/business addresses (need a
 * street key on read), ORG;department, TITLE, NOTE, BDAY read-only (never written:
 * Exchange would create a Birthdays-calendar event), categories. The photo lives on a
 * separate endpoint and is passed in as [bytes, mime].
 */
final class ContactMapper {
	private const ADDR_KEYS = ['street', 'city', 'state', 'postalCode', 'countryOrRegion'];

	/** @param array{0:string,1:string}|null $photo */
	public static function graphToVcard(array $json, ?array $photo = null): string {
		$card = new VCard([], false);
		$card->add('VERSION', '3.0');
		if (!empty($json['id'])) {
			$card->add('UID', (string)$json['id']);
		}
		$card->add('N', [
			(string)($json['surname'] ?? ''), (string)($json['givenName'] ?? ''), (string)($json['middleName'] ?? ''),
			(string)($json['title'] ?? ''), '',
		]);
		$fn = (string)($json['displayName'] ?? '');
		if ($fn === '') {
			$fn = trim(implode(' ', array_filter([(string)($json['givenName'] ?? ''), (string)($json['surname'] ?? '')])));
		}
		$card->add('FN', $fn);
		if (!empty($json['nickName'])) {
			$card->add('NICKNAME', (string)$json['nickName']);
		}
		/** @var list<mixed> $emails */
		$emails = is_array($json['emailAddresses'] ?? null) ? array_values($json['emailAddresses']) : [];
		foreach ($emails as $i => $e) {
			$addr = is_array($e) ? (string)($e['address'] ?? '') : '';
			if ($addr !== '') {
				$card->add('EMAIL', $addr, ['TYPE' => $i === 0 ? ['INTERNET', 'PREF'] : ['INTERNET']]);
			}
		}
		foreach ($json['businessPhones'] ?? [] as $num) {
			if ((string)$num !== '') {
				$card->add('TEL', (string)$num, ['TYPE' => ['WORK', 'VOICE']]);
			}
		}
		foreach ($json['homePhones'] ?? [] as $num) {
			if ((string)$num !== '') {
				$card->add('TEL', (string)$num, ['TYPE' => ['HOME', 'VOICE']]);
			}
		}
		if (!empty($json['mobilePhone'])) {
			$card->add('TEL', (string)$json['mobilePhone'], ['TYPE' => ['CELL']]);
		}
		if (!empty($json['jobTitle'])) {
			$card->add('TITLE', (string)$json['jobTitle']);
		}
		$org = (string)($json['companyName'] ?? '');
		$dept = (string)($json['department'] ?? '');
		if ($org !== '' || $dept !== '') {
			$card->add('ORG', $dept !== '' ? [$org, $dept] : [$org]);
		}
		if (!empty($json['personalNotes'])) {
			$card->add('NOTE', (string)$json['personalNotes']);
		}
		foreach (['businessAddress' => 'WORK', 'homeAddress' => 'HOME'] as $key => $type) {
			$a = $json[$key] ?? [];
			if (is_array($a) && $a !== [] && array_key_exists('street', $a)) {
				$card->add('ADR', ['', '', (string)($a['street'] ?? ''), (string)($a['city'] ?? ''), (string)($a['state'] ?? ''),
					(string)($a['postalCode'] ?? ''), (string)($a['countryOrRegion'] ?? '')], ['TYPE' => [$type]]);
			}
		}
		if (!empty($json['birthday'])) {
			try {
				$bd = new \DateTimeImmutable((string)$json['birthday']);
				$card->add('BDAY', $bd->format('Y-m-d'));
			} catch (\Exception) {
				// ignore unparsable birthdays
			}
		}
		$cats = array_values(array_filter(array_map('strval', $json['categories'] ?? []), static fn ($c) => $c !== ''));
		if ($cats !== []) {
			$card->add('CATEGORIES', $cats);
		}
		if ($photo !== null && $photo[0] !== '') {
			$mime = $photo[1];
			$sub = strtoupper(str_contains($mime, '/') ? explode('/', $mime, 2)[1] : ($mime ?: 'jpeg'));
			$card->add('PHOTO', $photo[0], ['ENCODING' => 'b', 'TYPE' => $sub]);
		}
		return $card->serialize();
	}

	/** @return list<string> upper-cased TYPE parameter values */
	private static function types(Property $prop): array {
		$param = $prop['TYPE'];
		if (!$param instanceof Parameter) {
			return [];
		}
		return array_map('strtoupper', array_map('strval', $param->getParts()));
	}

	/** vCard text -> Graph contact body for POST/PATCH (photo excluded). */
	public static function vcardToGraph(string $raw): array {
		/** @var VCard $card */
		$card = Reader::read($raw, Reader::OPTION_FORGIVING);
		$json = [];
		$n = IcalHelper::prop($card, 'N') !== null ? IcalHelper::prop($card, 'N')->getParts() : [];
		$n = array_pad(array_map('strval', $n), 5, '');
		$json['surname'] = $n[0];
		$json['givenName'] = $n[1];
		$json['middleName'] = $n[2];
		$json['title'] = $n[3];
		$json['nickName'] = IcalHelper::prop($card, 'NICKNAME') !== null ? (string)IcalHelper::prop($card, 'NICKNAME') : '';
		$json['displayName'] = IcalHelper::prop($card, 'FN') !== null ? (string)IcalHelper::prop($card, 'FN') : '';
		$json['jobTitle'] = IcalHelper::prop($card, 'TITLE') !== null ? (string)IcalHelper::prop($card, 'TITLE') : '';
		$org = IcalHelper::prop($card, 'ORG') !== null ? array_map('strval', IcalHelper::prop($card, 'ORG')->getParts()) : [];
		$json['companyName'] = $org[0] ?? '';
		$json['department'] = $org[1] ?? '';
		$json['personalNotes'] = IcalHelper::prop($card, 'NOTE') !== null ? (string)IcalHelper::prop($card, 'NOTE') : '';

		$emails = array_values($card->select('EMAIL'));
		usort($emails, static fn ($a, $b) => (in_array('PREF', self::types($a), true) || isset($a['PREF']) ? 0 : 1) <=> (in_array('PREF', self::types($b), true) || isset($b['PREF']) ? 0 : 1));
		$json['emailAddresses'] = [];
		foreach ($emails as $e) {
			$v = (string)$e->getValue();
			if ($v !== '') {
				$json['emailAddresses'][] = ['address' => $v];
			}
		}
		$business = [];
		$home = [];
		$mobile = '';
		foreach ($card->select('TEL') as $tel) {
			$num = (string)$tel->getValue();
			if ($num === '') {
				continue;
			}
			$t = self::types($tel);
			if (in_array('CELL', $t, true)) {
				$mobile = $mobile !== '' ? $mobile : $num;
			} elseif (in_array('HOME', $t, true)) {
				$home[] = $num;
			} else {
				$business[] = $num;
			}
		}
		$json['businessPhones'] = $business;
		$json['homePhones'] = $home;
		if ($mobile !== '') {
			$json['mobilePhone'] = $mobile;
		}
		foreach ($card->select('ADR') as $adr) {
			$parts = array_pad(array_map('strval', $adr->getParts()), 7, '');
			$addr = array_combine(self::ADDR_KEYS, array_slice($parts, 2, 5));
			if (!array_filter($addr)) {
				continue;
			}
			$key = in_array('HOME', self::types($adr), true) ? 'homeAddress' : 'businessAddress';
			$json[$key] ??= $addr;
		}
		// BDAY deliberately not written back.
		$cats = [];
		foreach ($card->select('CATEGORIES') as $c) {
			foreach ($c->getParts() as $part) {
				if ((string)$part !== '') {
					$cats[] = (string)$part;
				}
			}
		}
		$json['categories'] = $cats;
		return $json;
	}

	/** @return array{0:string,1:string}|null inline PHOTO as [bytes, mime] */
	public static function vcardPhoto(string $raw): ?array {
		/** @var VCard $card */
		$card = Reader::read($raw, Reader::OPTION_FORGIVING);
		$photo = IcalHelper::prop($card, 'PHOTO');
		if ($photo === null) {
			return null;
		}
		$value = $photo->getValue();
		if (is_string($value) && str_starts_with(strtolower($value), 'data:')) {
			[$header, $b64] = array_pad(explode(',', $value, 2), 2, '');
			$mime = explode(';', substr($header, 5), 2)[0] ?: 'image/jpeg';
			return [base64_decode($b64) ?: '', $mime];
		}
		if ($photo instanceof Property\Binary) {
			$sub = strtolower((string)(self::types($photo)[0] ?? 'JPEG'));
			return [(string)$value, 'image/' . $sub];
		}
		$encoding = $photo['ENCODING'];
		if ($encoding instanceof Parameter && in_array(strtoupper((string)$encoding->getValue()), ['B', 'BASE64'], true)) {
			$sub = strtolower((string)(self::types($photo)[0] ?? 'JPEG'));
			return [base64_decode((string)$value) ?: '', 'image/' . $sub];
		}
		return null;
	}
}
