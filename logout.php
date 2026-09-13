<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
logout_user();
header('Location: login.php');
