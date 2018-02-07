<?php
/**
 * @author Alexey Sadkov <alx.v.sadkov@gmail.com>
 *
 * @copyright Copyright (c) 2018, Alexey Sadkov <alx.v.sadkov@gmail.com>
 * @license GPL-2.0
 * 
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 * 
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 */

namespace OCA\Files_external_onedrive\Storage;

use Krizalys\Onedrive\Client;
use Icewind\Streams\IteratorDirectory;
use Icewind\Streams\RetryWrapper;


class OneDrive extends \OCP\Files\Storage\StorageAdapter {

    const APP_NAME = 'files_external_onedrive';

    /**
     * @var string
     */
    protected $clientId;

    /**
     * @var string
     */
    protected $clientSecret;

    /**
     * @var string
     */
    protected $accessToken;

    /**
     * @var Client
     */
    protected $client;

	private $driveFiles;

	private static $tempFiles = [];

	public function __construct($params) {
        if (isset($params['client_id']) && isset($params['client_secret']) && isset($params['token'])
            && isset($params['configured']) && $params['configured'] === 'true'
        ) {
            $this->clientId = $params['client_id'];
            $this->clientSecret = $params['client_secret'];
            $this->accessToken = unserialize($params['token']);

            $data=[
    	    'client_id' => $this->clientId,
	    	];

	        // Restore the previous state while instantiating this client to proceed in obtaining an access token.
    		$data['state'] = $this->accessToken;

            $this->client = new Client($data);
            if($this->client->getTokenExpire()<=1)
	            $this->client->renewAccessToken($this->clientSecret);

        } else {
            throw new \Exception('Creating OneDrive storage failed');
        }

	}

	public function getId() {
		return $this->clientId;
	}

	private function getDriveFile($path) {
		$path = trim($path, '/');
		if ($path === '.') {
			$path = '';
		}
		if (isset($this->driveFiles[$path])) {
			return $this->driveFiles[$path];
		} else if ($path === '') {
			$root = $this->client->fetchRoot();
			$this->driveFiles[$path] = $root;
			return $root;
		} else {
			$parentId = $this->getDriveFile('')->getId();
			$folderNames = explode('/', $path);
			$path = '';
			foreach ($folderNames as $name) {
				if(!$this->opendir($path)) return false;
				if ($path === '') {
					$path .= $name;
				} else {
					$path .= '/'.$name;
				}
				if (isset($this->driveFiles[$path])) {
					$parentId = $this->driveFiles[$path]->getId();
				}else{
					return false;
				}
			}
			return $this->driveFiles[$path];
		}
	}

	private function setDriveFile($path, $file) {
		$path = trim($path, '/');
		$this->driveFiles[$path] = $file;
		if ($file === false) {
			$len = strlen($path);
			foreach ($this->driveFiles as $key => $file) {
				if (substr($key, 0, $len) === $path) {
					unset($this->driveFiles[$key]);
				}
			}
		}
	}

	public function mkdir($path) {
		if (!$this->is_dir($path)) {
			$parentFolder = $this->getDriveFile(dirname($path));
			if ($parentFolder) {
				$folder = $this->client->createFolder(basename($path), $parentFolder->getId());
				return (bool)$folder;
			}
		}
		return false;
	}

	public function rmdir($path) {
		if (!$this->isDeletable($path)) {
			return false;
		}
		if (trim($path, '/') === '') {
			$dir = $this->opendir($path);
			if(is_resource($dir)) {
				while (($file = readdir($dir)) !== false) {
					if (!\OC\Files\Filesystem::isIgnoredDir($file)) {
						if (!$this->unlink($path.'/'.$file)) {
							return false;
						}
					}
				}
				closedir($dir);
			}
			$this->driveFiles = [];
			return true;
		} else {
			return $this->unlink($path);
		}
	}

	public function opendir($path) {
		$folder = $this->getDriveFile($path);
		if($folder) {
			$files = [];
			$duplicates = [];
		    $objects = $folder->fetchObjects();
		    foreach ($objects as $object) {
					$name = $object->getName();
					if ($path === '') {
						$filepath = $name;
					} else {
						$filepath = $path.'/'.$name;
					}
					$this->setDriveFile($filepath, $object);
					$files[] = $name;
	    	}
	    	return IteratorDirectory::wrap($files);
		}else{
			return false;
		}
	}

	public function stat($path) {
		$file = $this->getDriveFile($path);
		if ($file) {
			$stat = [];
			if ($file->isFolder()) {
				$stat['size'] = 0;
			} else {
				$stat['size'] = $file->getSize();
			}
			$stat['atime'] = $file->getUpdatedTime();
			$stat['mtime'] = $file->getUpdatedTime();
			$stat['ctime'] = $file->getCreatedTime();
			return $stat;
		} else {
			return false;
		}
	}

	public function filetype($path) {
		if ($path === '') {
			return 'dir';
		} else {
			$file = $this->getDriveFile($path);
			if ($file) {
				if ($file->isFolder()) {
					return 'dir';
				} else {
					return 'file';
				}
			} else {
				return false;
			}
		}
	}

	public function isUpdatable($path) {
		$file = $this->getDriveFile($path);
		if ($file) {
			return true;
		} else {
			return false;
		}
	}

	public function file_exists($path) {
		return (bool)$this->getDriveFile($path);
	}

	public function unlink($path) {
		$file = $this->getDriveFile($path);
		if ($file) {
			$result = $this->client->deleteObject($file->getId());
			$this->setDriveFile($path, false);
			return true;
		} else {
			return false;
		}
	}

	public function rename($path1, $path2) {
		$file = $this->getDriveFile($path1);
		if ($file) {
			$newFile = $this->getDriveFile($path2);
			if (dirname($path1) === dirname($path2)) {
				if ($newFile) {
					$this->unlink($path2);
					$this->client->updateObject($file->getId(), array('name'=>basename(($path2))));
				} else {
					$this->client->updateObject($file->getId(), array('name'=>basename(($path2))));
				}
			} else {
				// Change file parent
				$parentFolder2 = $this->getDriveFile(dirname($path2));
				if ($parentFolder2) {
					$this->client->updateObject($file->getId(), array('name'=>basename(($path2))));
					$file->move($parentFolder2->getId());
				} else {
					return false;
				}
			}
			return true;
		} else {
			return false;
		}
	}

	public function fopen($path, $mode) {
		$pos = strrpos($path, '.');
		if ($pos !== false) {
			$ext = substr($path, $pos);
		} else {
			$ext = '';
		}
		switch ($mode) {
			case 'r':
			case 'rb':
				$file = $this->getDriveFile($path);
				if ($file) {
					$tmpFile = \OCP\Files::tmpFile();
					self::$tempFiles[$tmpFile] = $path;
					file_put_contents($tmpFile, $file->fetchContent());
					return fopen($tmpFile, 'r');
				}
				return false;
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				$tmpFile = \OCP\Files::tmpFile($ext);
				\OC\Files\Stream\Close::registerCallback($tmpFile, [$this, 'writeBack']);
				if ($this->file_exists($path)) {
					$source = $this->fopen($path, 'rb');
					file_put_contents($tmpFile, $source);
				}
				self::$tempFiles[$tmpFile] = $path;
				return fopen('close://'.$tmpFile, $mode);
		}
	}

	public function writeBack($tmpFile) {
		if (isset(self::$tempFiles[$tmpFile])) {
			$path = self::$tempFiles[$tmpFile];
			$parentFolder = $this->getDriveFile(dirname($path));
			if ($parentFolder) {
				$mimetype = \OC::$server->getMimeTypeDetector()->detect($tmpFile);
				$params = [
					'mimeType' => $mimetype,
					'uploadType' => 'media'
				];

				$result=$parentFolder->createFile(basename($path), file_get_contents($tmpFile), []);
				if ($result) {
					$this->setDriveFile($path, $result);
				}
			}
			unlink($tmpFile);
		}
	}

	public function free_space($path) {
		$about = $this->client->fetchQuota();
		return $about->available;
	}

	public function touch($path, $mtime = null) {
		$file = $this->getDriveFile($path);
		$result = false;
		if ($file) {
			$result = $this->client->updateObject($file->getId(), array('name'=>basename(($path))));
		} else {
			$parentFolder = $this->getDriveFile(dirname($path));
			if ($parentFolder) {
				$result=$parentFolder->createFile(basename($path), '', []);
			}
		}

		if ($result) {
			$this->setDriveFile($path, $result);
		}
		return (bool)$result;
	}

	public function test() {
		if ($this->free_space('')) {
			return true;
		}
		return false;
	}

}
