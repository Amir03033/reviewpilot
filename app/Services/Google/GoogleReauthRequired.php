<?php

namespace App\Services\Google;

use RuntimeException;

/** The stored refresh token no longer works; the owner must reconnect Google. */
class GoogleReauthRequired extends RuntimeException {}
