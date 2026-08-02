<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/web_auth.php';
pvdash_logout();
header('Location: ./');
exit;
