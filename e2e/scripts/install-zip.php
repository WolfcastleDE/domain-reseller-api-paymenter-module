<?php
// Installs the extension the way an admin does: uploading the release zip.
require __DIR__ . '/boot.php';

use App\Helpers\ExtensionHelper;
use App\Services\Extensions\UploadExtensionService;

section('Zip installation');
$zipPath = '/tmp/DomainResellerApi-e2e.zip';
@unlink($zipPath);
$zip = new ZipArchive;
$zip->open($zipPath, ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/e2e/extension', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $zip->addFile($file->getPathname(), 'DomainResellerApi/' . substr($file->getPathname(), strlen('/e2e/extension/')));
}
$zip->close();

$type = app(UploadExtensionService::class)->handle($zipPath);
ok('uploaded zip detected as "' . $type . '" extension', $type === 'server');
ok('files installed to extensions/Servers/DomainResellerApi', is_file(base_path('extensions/Servers/DomainResellerApi/DomainResellerApi.php')));
ok('extension still discovered', in_array('DomainResellerApi', array_column(ExtensionHelper::getExtensions('server'), 'name'), true));

exit($GLOBALS['failures']);
