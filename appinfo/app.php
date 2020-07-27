<?php

use OCA\Files_External_FTP\AppInfo\Application;

if (class_exists('\OCA\Files_External\AppInfo\Application')) {
	OC_App::loadApp('files_external');
	(\OC::$server->query(Application::class))->register();
}
