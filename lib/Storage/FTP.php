<?php
/**
 * @author Robin Appelman <robin@icewind.nl>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_External_FTP\Storage;

use Aws\Api\Parser\Exception\ParserException;
use Icewind\Streams\CallbackWrapper;
use Icewind\Streams\CountWrapper;
use Icewind\Streams\IteratorDirectory;
use OC\Files\Storage\Common;
use OC\Files\Storage\PolyFill\CopyDirectory;
use OCP\Constants;
use OCP\Files\StorageNotAvailableException;

class FTP extends Common {
	use CopyDirectory;

	private $root;
	private $host;
	private $password;
	private $username;
	private $secure;
	private $port;

	/** @var resource */
	private $connection;

	public function __construct($params) {
		if (isset($params['host']) && isset($params['user']) && isset($params['password'])) {
			$this->host = $params['host'];
			$this->username = $params['user'];
			$this->password = $params['password'];
			if (isset($params['secure'])) {
				if (is_string($params['secure'])) {
					$this->secure = ($params['secure'] === 'true');
				} else {
					$this->secure = (bool)$params['secure'];
				}
			} else {
				$this->secure = false;
			}
			$this->root = isset($params['root']) ? '/' . ltrim($params['root']) : '/';
			$this->port = isset($params['port']) ? $params['port'] : 21;

		} else {
			throw new \Exception('Creating \OCA\Files_External_FTP\FTP storage failed');
		}
	}

	protected function getConnection() {
		if (!$this->connection) {
			if ($this->secure) {
				$this->connection = ftp_ssl_connect($this->host, $this->port);
			} else {
				$this->connection = ftp_connect($this->host, $this->port);
			}

			if ($this->connection === false) {
				throw new StorageNotAvailableException("Failed to connect to ftp");
			}

			if (ftp_login($this->connection, $this->username, $this->password) === false) {
				throw new StorageNotAvailableException("Failed to connect to login to ftp");
			}

			ftp_pasv($this->getConnection(), true);
		}

		return $this->connection;
	}

	public function getId() {
		return 'ftp::' . $this->username . '@' . $this->host . '/' . $this->root;
	}

	public function disconnect() {
		if ($this->connection) {
			ftp_close($this->connection);
		}
		$this->connection = null;
	}

	public function __destruct() {
		$this->disconnect();
	}

	protected function buildPath($path) {
		return \OC\Files\Filesystem::normalizePath($this->root . '/' . $path);
	}

	public static function checkDependencies() {
		if (function_exists('ftp_login')) {
			return (true);
		} else {
			return ['ftp'];
		}
	}

	public function filemtime($path) {
		$result = @ftp_mdtm($this->getConnection(), $this->buildPath($path));

		if ($result === -1) {
			if ($this->is_dir($path)) {
				$list = @ftp_mlsd($this->getConnection(), $this->buildPath($path));
				if (!$list) {
					return time();
				} else {
					return \DateTime::createFromFormat('YmdGis', $list[0]['modify'])->getTimestamp();
				}
			} else {
				return false;
			}
		} else {
			return $result;
		}
	}

	public function filesize($path) {
		$result = @ftp_size($this->getConnection(), $this->buildPath($path));
		if ($result === -1) {
			return false;
		} else {
			return $result;
		}
	}

	public function rmdir($path) {
		$result = @ftp_rmdir($this->getConnection(), $this->buildPath($path));
		// recursive rmdir support depends on the ftp server
		if ($result) {
			return $result;
		} else {
			return $this->recursiveRmDir($path);
		}
	}

	protected function listContents($path) {
		return @ftp_mlsd($this->getConnection(), $this->buildPath($path));
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	private function recursiveRmDir($path) {
		$contents = @ftp_mlsd($this->getConnection(), $this->buildPath($path));
		$result = true;
		foreach ($contents as $content) {
			if ($content['name'] === '.' || $content['name'] === '..') {
				continue;
			}
			if ($content['type'] === 'dir') {
				$result = $result && $this->recursiveRmDir($path . '/' . $content['name']);
			} else if ($content['type'] === 'file') {
				$result = $result && @ftp_delete($this->getConnection(), $this->buildPath($path . '/' . $content['name']));
			}
		}
		$result = $result && @ftp_rmdir($this->getConnection(), $this->buildPath($path));

		return $result;
	}

	public function test() {
		try {
			return @ftp_systype($this->getConnection()) !== false;
		} catch (\Exception $e) {
			return false;
		}
	}

	public function stat($path) {
		if (!$this->file_exists($path)) {
			return false;
		}
		return [
			'mtime' => $this->filemtime($path),
			'size' => $this->filesize($path),
		];
	}

	public function file_exists($path) {
		if ($path === '' || $path === '.' || $path === '/') {
			return true;
		}
		return $this->filetype($path) !== false;
	}

	public function unlink($path) {
		switch ($this->filetype($path)) {
			case 'dir':
				return $this->rmdir($path);
			case 'file':
				return @ftp_delete($this->getConnection(), $this->buildPath($path));
			default:
				return false;
		}
	}

	public function opendir($path) {
		$files = @ftp_nlist($this->getConnection(), $this->buildPath($path));
		$files = array_map(function($name) {
			if (strpos($name, '/') !== false) {
				$name = basename($name);
			}
			return $name;
		}, $files);
		return IteratorDirectory::wrap($files);
	}

	public function mkdir($path) {
		if ($this->is_dir($path)) {
			return false;
		}
		return @ftp_mkdir($this->getConnection(), $this->buildPath($path)) !== false;
	}

	public function is_dir($path) {
		if (@ftp_chdir($this->getConnection(), $this->buildPath($path)) === true) {
			@ftp_chdir($this->getConnection(), '/');
			return true;
		} else {
			return false;
		}
	}

	public function is_file($path) {
		return $this->filesize($path) !== false;
	}

	public function filetype($path) {
		if ($this->is_dir($path)) {
			return 'dir';
		} else if ($this->is_file($path)) {
			return 'file';
		} else {
			return false;
		}
	}

	public function fopen($path, $mode) {
		$useExisting = true;
		switch ($mode) {
			case 'r':
			case 'rb':
				return $this->readStream($path);
			case 'w':
			case 'w+':
			case 'wb':
			case 'wb+':
				$useExisting = false;
			// no break
			case 'a':
			case 'ab':
			case 'r+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				//emulate these
				if ($useExisting and $this->file_exists($path)) {
					if (!$this->isUpdatable($path)) {
						return false;
					}
					$tmpFile = $this->getCachedFile($path);
				} else {
					if (!$this->isCreatable(dirname($path))) {
						return false;
					}
					$tmpFile = \OC::$server->getTempManager()->getTemporaryFile();
				}
				$source = fopen($tmpFile, $mode);
				return CallbackWrapper::wrap($source, null, null, function () use ($tmpFile, $path) {
					$this->writeStream($path, fopen($tmpFile, 'r'));
					unlink($tmpFile);
				});
		}
		return false;
	}

	public function writeStream(string $path, $stream, int $size = null): int {
		if ($size === null) {
			$stream = CountWrapper::wrap($stream, function ($writtenSize) use (&$size) {
				$size = $writtenSize;
			});
		}

		@ftp_fput($this->getConnection(), $this->buildPath($path), $stream, FTP_BINARY);
		fclose($stream);

		return $size;
	}

	public function readStream(string $path) {
		$stream = fopen('php://temp', 'w+');
		$result = @ftp_fget($this->getConnection(), $stream, $this->buildPath($path), FTP_BINARY);
		rewind($stream);

		if (!$result) {
			fclose($stream);
			return false;
		}
		return $stream;
	}

	public function touch($path, $mtime = null) {
		if ($this->file_exists($path)) {
			return false;
		} else {
			$this->file_put_contents($path, '');
			return true;
		}
	}

	public function rename($path1, $path2) {
		$this->unlink($path2);
		return @ftp_rename($this->getConnection(), $this->buildPath($path1), $this->buildPath($path2));
	}

	public function getDirectoryContent($directory): \Traversable {
		$files = ftp_mlsd($this->getConnection(), $this->buildPath($directory));
		$mimeTypeDetector = \OC::$server->getMimeTypeDetector();

		foreach ($files as $file) {
			$name = $file['name'];
			if (strpos($name, '/') !== false) {
				$name = basename($name);
			}

			if ($file['name'] === '.' || $file['name'] === '..') {
				continue;
			}
			$permissions = Constants::PERMISSION_ALL - Constants::PERMISSION_CREATE;
			$isDir = $file['type'] === 'dir';
			if ($isDir) {
				$permissions += Constants::PERMISSION_CREATE;
			}

			$data = [];
			$data['mimetype'] = $isDir ? 'httpd/unix-directory' : $mimeTypeDetector->detectPath($name);
			$data['mtime'] = \DateTime::createFromFormat('YmdGis', $file['modify'])->getTimestamp();
			if ($data['mtime'] === false) {
				$data['mtime'] = time();
			}
			if ($isDir) {
				$data['size'] = -1; //unknown
			} else {
				$data['size'] = $this->filesize($directory . '/' . $name);
			}
			$data['etag'] = uniqid();
			$data['storage_mtime'] = $data['mtime'];
			$data['permissions'] = $permissions;
			$data['name'] = $name;

			yield $data;
		}
	}
}
