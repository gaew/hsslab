<?php
// /config/app.php
declare(strict_types=1);

// ปรับ path ให้ตรงเครื่องคุณ
define('STORAGE_ROOT', realpath(__DIR__ . '/../storage') ?: (__DIR__ . '/../storage'));
define('PUBLIC_FILES_ROOT', 'C:\\inetpub\\wwwroot\\hsslab\\public_files');
define('APP_SECRET', 'HSSLAB_9x3$KpL#2026@CentralRef!SecureToken');