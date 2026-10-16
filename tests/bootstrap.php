<?php

declare(strict_types=1);

// Unit tests run without a Nextcloud server: only the OCP stubs and sabre/vobject are needed.
require_once __DIR__ . '/../vendor/autoload.php';

// Test doubles live in one file (not PSR-4 discoverable).
require_once __DIR__ . '/Unit/Sync/Fakes.php';
require_once __DIR__ . '/Unit/TestDoubles.php';
