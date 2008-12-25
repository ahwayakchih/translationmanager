<?php

	class TranslationManager {

		private $_Parent;

		function __construct(&$parent) {
			$this->_Parent = $parent;
		}

		public function enable($lang) {
			if (strlen($lang) < 1) return false;

			if (!file_exists(TranslationManager::filePath($lang, 'symphony'))) return false;

			$this->_Parent->Configuration->set('lang', $lang, 'symphony');
			return $this->_Parent->saveConfig();
		}

		public function listAll($name = NULL) {
			$result = array();

			if ($name === NULL) {
				$result = $this->listAll('symphony');
				foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
					$result = array_merge_recursive($result, $this->listAll($extension));
				}
			}
			else if (strlen($name) > 0) {
				$path = dirname(TranslationManager::filePath('en', $name));
				foreach (glob($path.'/lang.*.php') as $file) {
					$lang = preg_replace('/^[\w\W]+\/lang.(\w+).php/', '\\1', $file);
					$result[$lang][] = $name;
				}
			}

			return $result;
		}

		public function listExtensions($lang) {
			if (strlen($lang) < 1) return array();
			$result = array();

			if (file_exists(TranslationManager::filePath($lang, 'symphony'))) $result[] = 'symphony';

			foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
				if (file_exists(TranslationManager::filePath($lang, $extension))) $result[] = $extension;
			}

			return $result;
		}

		public function get($lang, $name = NULL) {
			if ($name === NULL) {
				$result = $this->get($lang, 'symphony');
				foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
					// array_merge_recursive duplicates data inside 'about' (so there are two names of language instead of one string) :(.
					$temp = $this->get($lang, $extension);
					// TODO: Merging about doesn't make much sense - new info will replace old,
					//       so data about modifications dates and authors is useless in this case :(
					if (is_array($temp['about'])) $result['about'] = array_merge($result['about'], $temp['about']);
					if (is_array($temp['dictionary'])) $result['dictionary'] = array_merge($result['dictionary'], $temp['dictionary']);
					if (is_array($temp['transliterations'])) $result['transliterations'] = array_merge($result['transliterations'], $temp['transliterations']);
				}
				return $result;
			}
			else if (strlen($name) < 1) return array();

			$file = TranslationManager::filePath($lang, $name);
			if (!file_exists($file)) return array();

			$data = file_get_contents($file);
			eval('?>'.$data);

			return array(
				'about' => (is_array($about) ? $about : array()),
				'dictionary' => (is_array($dictionary) ? $dictionary : array()),
				'transliterations' => (is_array($transliterations) ? $transliterations : array()),
			);
		}

		public function set($lang, $data, $name) {
			if (strlen($lang) < 1 || !is_array($data) || empty($name)) return false;

			$isSymphony = ($name == 'symphony');

			if (!is_array($data['about'])) $data['about'] = array();
			$this->__updateAuthors($data['about']);

			if (!is_array($data['dictionary'])) $data['dictionary'] = array();

			if (!is_array($data['transliterations']) || ($isSymphony && empty($data['transliterations'])))
				 $data['transliterations'] = ($isSymphony ? TranslationManager::defaultTransliterations() : array());


			$file = TranslationManager::filePath($lang, $name);
			if (!$isSymphony && !is_dir(dirname($file))) {
				if (!General::realiseDirectory(dirname($file), $this->_Parent->Configuration->get('write_mode', 'directory'))) return false;
			}

			return General::writeFile($file, TranslationManager::toPHP($data), $this->_Parent->Configuration->get('write_mode', 'file'));
		}

		public function remove($lang, $name = NULL) {
			// TODO: remove requirement for 'symphony' in current language
			if (strlen($lang) < 1 || ($lang == $this->_Parent->Configuration->get('lang', 'symphony') && $name == 'symphony')) return false;

			if ($name === NULL) {
				if (!$this->remove($lang, 'symphony')) return false;
				foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
					if (!$this->remove($lang, $extension)) return false;
				}
				return true;
			}

			$file = TranslationManager::filePath($lang, $name);
			if (file_exists($file) && !General::deleteFile($file)) return false;

			return true;
		}

		public function defaultDictionary($name = NULL) {
			$strings = array();
			$strings[] = array(); // Warnings placeholder
			foreach ($this->__dictionaryPaths($name) as $path) {
				$files = General::listStructure(DOCROOT."/$path", array('php', 'tpl'), false, 'asc');
				if (empty($files['filelist'])) continue;
				foreach ($files['filelist'] as $file) {
					$temp = $this->__findStrings(DOCROOT."/$path/$file", $strings);
				}
			}
			return $strings;
		}

		private function __updateAuthors(&$about) {
			$name = trim($this->_Parent->Author->getFullName());
			$date = date('Y-m-d');
			if (isset($about['author']['name']) && trim($about['author']['name']) && trim($about['author']['name']) != $name) {
				$about['author'] = array(trim($about['author']['name']) => $about['author']);
			}
			$about['author'][$name] = array(
				'name' => $name,
				'website' => URL,
				'email' => trim($this->_Parent->Author->get('email')),
				'release-date' => $date,
			);
			$about['release-date'] = $date;
			if (is_array($about['author']) && is_array($about['author'][0])) uasort($about['author'], array($this, '__sortAuthorsByDate'));

		}

		private function __sortAuthorsByDate($a, $b) {
			return strnatcmp($a['release-date'], $b['release-date']);
		}

		private function __dictionaryPaths($name = NULL) {
			$paths = array();
			if ($name === NULL || $name == 'symphony') {
				$paths += array(
					'symphony/content',
					'symphony/template',
					'symphony/lib/toolkit',
					'symphony/lib/toolkit/data-sources',
					'symphony/lib/toolkit/events',
					'symphony/lib/toolkit/fields',
				);
			}

			$names = array();
			if ($name === NULL) {
				$extensions = $this->_Parent->ExtensionManager->listAll();
				if (is_array($extensions)) $names = array_keys($extensions);
			}
			else if ($name != 'symphony' && strlen($name) > 0) $names[] = $name;

			foreach ($names as $name) {
				$paths += array(
					'extensions/'.$name.'/content',
					'extensions/'.$name.'/template',
					'extensions/'.$name.'/data-sources',
					'extensions/'.$name.'/events',
					'extensions/'.$name.'/fields',
					'extensions/'.$name.'/lib', // TODO: test on extensions which use "non-symphony" code in lib directory.
					'extensions/'.$name
				);
			}

			return $paths;
		}

		private function __findStrings($path, &$result) {
			if (!file_exists($path)) return array();

			$tokens = file_get_contents($path);
			if (empty($tokens)) return array();

			$tokens = token_get_all($tokens);
			if (!is_array($tokens) || empty($tokens)) return array();

			if (!is_array($result) || !is_array($result[0])) $result[0] = array(); // Placeholder for warnings

			$i = -1;
			$on = false;
			$warn = false;
			$depth = -1;
			$line = 0;
			while ($tokens[++$i]) {
				if (!$on) {
					if (is_array($tokens[$i]) && $tokens[$i][1] == '__' && $tokens[$i][0] == T_STRING) { // __ function call starts parsable string
						$on = $i;
						$depth = -1;
						$warn = false;
						$line = $tokens[$i][2];
					}
					continue;
				}
				$isArray = is_array($tokens[$i]);

				if ($isArray) {
					$line = $tokens[$i][2];
					if ($tokens[$i][0] == T_CONSTANT_ENCAPSED_STRING) { // 'some value' or "some value"
						// Constant strings are tokenized with wrapping quote/doublequote included. Text is not unescaped, so things like "foo\'s" are there too.
						$temp = trim(str_replace('\\'.$tokens[$i][1]{0}, $tokens[$i][1]{0}, $tokens[$i][1]), $tokens[$i][1]{0});
						if ($temp) $result[$temp][$path][] = $tokens[$i][2];
						else $warn = true; // Empty string?
						continue;
					}
				}

				if (($isArray && $tokens[$i][0] == T_START_HEREDOC) || // <<<END some value END
					(!$isArray && $tokens[$i] == '"')) { // "some $variable inside parsable text"
					$temp = '';
					while ($tokens[++$i]) {
						if (is_array($tokens[$i])) $temp .= $tokens[$i][1]; // Text is T_ENCAPSED_AND_WHITESPACE, but it can be also variable, function call, etc...
						else $temp .= $tokens[$i];
					}
					if ($temp) $result[$temp][$path][] = $line;
					$warn = true;
					continue;
				}

				if ($isArray) continue;

				switch ($tokens[$i]) {
					case '(': // Open inner parenthesis
						if (++$depth > 0) $warn = true;
						break;
					case ')': // Close inner parenthesis
						 if (--$depth >= 0) break;
					case ',':
						if ($depth > 0) break;
						// Comma marking end of first argument passed to __()
						if ($warn) { // Warn about using parsable strings and/or variable text for translatable data
							$temp = '';
							$l = $tokens[$on][2];
							while ($on <= $i) {
								if (is_array($tokens[$on])) $temp .= $tokens[$on][1];
								else $temp .= $tokens[$on];
								$on++;
							}
							$result[0][$temp][$path][] = $l;
						}
						$on = false;
						break;
					//case '.': // Gluing strings is not "clean" - use %s tokens! ;P
					default: // Any other usage of PHP in place of simple string is not advised
						$warn = true;
						break;
				}
			}
		}

		public static function toPHP($data) {
			$php = '<'."?php\n\n";
			$php .= '$about = '.var_export($data['about'], true).";\n\n";
			$php .= '$dictionary = '.var_export($data['dictionary'], true).";\n\n";
			$php .= '$transliterations = '.var_export($data['transliterations'], true).";\n\n";
			$php .= '?>';

			return $php;
		}

		public static function filePath($lang, $name) {
			if ($name == 'symphony') return LANG."/lang.$lang.php";
			else return EXTENSIONS."/$name/lang/lang.$lang.php";
		}

		// Default transliterations come from default lang.en.php, written by developers of Symphony
		public static function defaultTransliterations() {
			static $transliterations;
			if (is_array($transliterations)) return $transliterations;

			// Alphabetical:
			$transliterations = array(
				'/À/' => 'A', 		'/Á/' => 'A', 		'/Â/' => 'A', 		'/Ã/' => 'A', 		'/Ä/' => 'Ae', 		
				'/Å/' => 'A', 		'/Ā/' => 'A', 		'/Ą/' => 'A', 		'/Ă/' => 'A', 		'/Æ/' => 'Ae', 		
				'/Ç/' => 'C', 		'/Ć/' => 'C', 		'/Č/' => 'C', 		'/Ĉ/' => 'C', 		'/Ċ/' => 'C', 		
				'/Ď/' => 'D', 		'/Đ/' => 'D', 		'/Ð/' => 'D', 		'/È/' => 'E', 		'/É/' => 'E', 		
				'/Ê/' => 'E', 		'/Ë/' => 'E', 		'/Ē/' => 'E', 		'/Ę/' => 'E', 		'/Ě/' => 'E', 		
				'/Ĕ/' => 'E', 		'/Ė/' => 'E', 		'/Ĝ/' => 'G', 		'/Ğ/' => 'G', 		'/Ġ/' => 'G', 		
				'/Ģ/' => 'G', 		'/Ĥ/' => 'H', 		'/Ħ/' => 'H', 		'/Ì/' => 'I', 		'/Í/' => 'I', 		
				'/Î/' => 'I', 		'/Ï/' => 'I', 		'/Ī/' => 'I', 		'/Ĩ/' => 'I', 		'/Ĭ/' => 'I', 		
				'/Į/' => 'I', 		'/İ/' => 'I', 		'/Ĳ/' => 'Ij', 		'/Ĵ/' => 'J', 		'/Ķ/' => 'K', 		
				'/Ł/' => 'L', 		'/Ľ/' => 'L', 		'/Ĺ/' => 'L', 		'/Ļ/' => 'L', 		'/Ŀ/' => 'L', 		
				'/Ñ/' => 'N', 		'/Ń/' => 'N', 		'/Ň/' => 'N', 		'/Ņ/' => 'N', 		'/Ŋ/' => 'N', 		
				'/Ò/' => 'O', 		'/Ó/' => 'O', 		'/Ô/' => 'O', 		'/Õ/' => 'O', 		'/Ö/' => 'Oe', 		
				'/Ø/' => 'O', 		'/Ō/' => 'O', 		'/Ő/' => 'O', 		'/Ŏ/' => 'O', 		'/Œ/' => 'Oe', 		
				'/Ŕ/' => 'R', 		'/Ř/' => 'R', 		'/Ŗ/' => 'R', 		'/Ś/' => 'S', 		'/Š/' => 'S', 		
				'/Ş/' => 'S', 		'/Ŝ/' => 'S', 		'/Ș/' => 'S', 		'/Ť/' => 'T', 		'/Ţ/' => 'T', 		
				'/Ŧ/' => 'T', 		'/Ț/' => 'T', 		'/Ù/' => 'U', 		'/Ú/' => 'U', 		'/Û/' => 'U', 		
				'/Ü/' => 'Ue', 		'/Ū/' => 'U', 		'/Ů/' => 'U', 		'/Ű/' => 'U', 		'/Ŭ/' => 'U', 		
				'/Ũ/' => 'U', 		'/Ų/' => 'U', 		'/Ŵ/' => 'W', 		'/Ý/' => 'Y', 		'/Ŷ/' => 'Y', 		
				'/Ÿ/' => 'Y', 		'/Y/' => 'Y', 		'/Ź/' => 'Z', 		'/Ž/' => 'Z', 		'/Ż/' => 'Z', 		
				'/Þ/' => 'T', 		'/à/' => 'a', 		'/á/' => 'a', 		'/â/' => 'a', 		'/ã/' => 'a', 		
				'/ä/' => 'ae', 		'/å/' => 'a', 		'/ā/' => 'a', 		'/ą/' => 'a', 		'/ă/' => 'a', 		
				'/æ/' => 'ae', 		'/ç/' => 'c', 		'/ć/' => 'c', 		'/č/' => 'c', 		'/ĉ/' => 'c', 		
				'/ċ/' => 'c', 		'/ď/' => 'd', 		'/đ/' => 'd', 		'/ð/' => 'd', 		'/è/' => 'e', 		
				'/é/' => 'e', 		'/ê/' => 'e', 		'/ë/' => 'e', 		'/ē/' => 'e', 		'/ę/' => 'e', 		
				'/ě/' => 'e', 		'/ĕ/' => 'e', 		'/ė/' => 'e', 		'/ƒ/' => 'f', 		'/ĝ/' => 'g', 		
				'/ğ/' => 'g', 		'/ġ/' => 'g', 		'/ģ/' => 'g', 		'/ĥ/' => 'h', 		'/ħ/' => 'h', 		
				'/ì/' => 'i', 		'/í/' => 'i', 		'/î/' => 'i', 		'/ï/' => 'i', 		'/ī/' => 'i', 		
				'/ĩ/' => 'i', 		'/ĭ/' => 'i', 		'/į/' => 'i', 		'/ı/' => 'i', 		'/ĳ/' => 'ij', 		
				'/ĵ/' => 'j', 		'/ķ/' => 'k', 		'/ĸ/' => 'k', 		'/ł/' => 'l', 		'/ľ/' => 'l', 		
				'/ĺ/' => 'l', 		'/ļ/' => 'l', 		'/ŀ/' => 'l', 		'/ñ/' => 'n', 		'/ń/' => 'n', 		
				'/ň/' => 'n', 		'/ņ/' => 'n', 		'/ŉ/' => 'n', 		'/ŋ/' => 'n', 		'/ò/' => 'o', 		
				'/ó/' => 'o', 		'/ô/' => 'o', 		'/õ/' => 'o', 		'/ö/' => 'oe', 		'/ø/' => 'o', 		
				'/ō/' => 'o', 		'/ő/' => 'o', 		'/ŏ/' => 'o', 		'/œ/' => 'oe', 		'/ŕ/' => 'r', 		
				'/ř/' => 'r', 		'/ŗ/' => 'r', 		'/ú/' => 'u', 		'/û/' => 'u', 		'/ü/' => 'ue', 		
				'/ū/' => 'u', 		'/ů/' => 'u', 		'/ű/' => 'u', 		'/ŭ/' => 'u', 		'/ũ/' => 'u', 		
				'/ų/' => 'u', 		'/ŵ/' => 'w', 		'/ý/' => 'y', 		'/ÿ/' => 'y', 		'/ŷ/' => 'y', 		
				'/y/' => 'y', 		'/ž/' => 'z', 		'/ż/' => 'z', 		'/ź/' => 'z', 		'/þ/' => 't', 		
				'/ß/' => 'ss', 		'/ſ/' => 'ss' 
			);
			// Symbolic:
			$transliterations += array(
				'/\(/'	=> null,		'/\)/'	=> null,		'/,/'	=> null,
				'/–/'	=> '-',			'/－/'	=> '-',			'/„/'	=> '"',
				'/“/'	=> '"',			'/”/'	=> '"',			'/—/'	=> '-',
			);
			// Ampersands:
			$transliterations += array(
				'/^&(?!&)$/'	=> 'and',
				'/^&(?!&)/'		=> 'and-',
				'/&(?!&)&/'		=> '-and',
				'/&(?!&)/'		=> '-and-'
			);

			return $transliterations;
		}

	}

?>