<?php

namespace XcVm\Public\Controllers\Admin;

use Throwable;

/**
 * Admin login controller.
 *
 * The login view owns its complete HTML document and bootstrap sequence.
 */
class LoginController extends BaseAdminController {
	public function index() {
		$viewDirectory = MAIN_HOME . 'Public/Views/admin/';
		$viewFile = $viewDirectory . 'login.php';

		if (!is_file($viewFile) || !is_readable($viewFile)) {
			http_response_code(500);
			error_log('XDS login view is missing or unreadable: ' . $viewFile);
			echo 'XDS login view is unavailable.';
			return;
		}

		if (!@chdir($viewDirectory)) {
			http_response_code(500);
			error_log('XDS could not enter admin view directory: ' . $viewDirectory);
			echo 'XDS admin view directory is unavailable.';
			return;
		}

		$initialBufferLevel = ob_get_level();
		ob_start();

		try {
			require $viewFile;
			$output = ob_get_clean();

			if ($output === '' && http_response_code() === 200 && !headers_sent()) {
				http_response_code(500);
				error_log('XDS login rendered an empty response without redirect or HTTP error.');
				echo 'XDS login failed to render. Check the PHP error log.';
				return;
			}

			echo $output;
		} catch (Throwable $exception) {
			while (ob_get_level() > $initialBufferLevel) {
				ob_end_clean();
			}

			http_response_code(500);
			error_log(sprintf(
				'XDS login exception: %s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			));
			echo 'XDS login initialization failed. Check the PHP error log.';
		}
	}
}
