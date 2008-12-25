<?php

	class TranslationManager {

		private $_Parent;

		function __construct(&$parent) {
			$this->_Parent = $parent;
		}

		public function enable($lang) {
			if (strlen($lang) < 1) return false;

			if (!file_exists(LANG."/lang.$lang.php")) return false;

			$this->_Parent->Configuration->set('lang', $lang, 'symphony');
			return $this->_Parent->saveConfig();
		}

		public function listAll($name=NULL) {
			$result = array();

			if ($name === NULL) {
				$result = $this->listAll('symphony');
				foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
					$result = array_merge_recursive($result, $this->listAll($extension));
				}
			}
			else if (strlen($name) > 0) {
				$path = ($name == 'symphony' ? LANG : EXTENSIONS."/$name/lang");
				foreach (glob($path.'/lang.*.php') as $file) {
					$lang = preg_replace('/^[\w\W]+\/lang.(\w+).php/', '\\1', $file);
					//$result[$lang][$name] = $file;
					$result[$lang][] = $name;
				}
			}

			return $result;
		}

		public function listExtensions($lang) {
			if (strlen($lang) < 1) return array();
			$result = array();

			$file = LANG."/lang.$lang.php";
			if (file_exists($file)) $result[] = 'symphony';

			foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
				$file = EXTENSIONS."/$extension/lang/lang.$lang.php";
				if (file_exists($file)) $result[] = $extension;
			}

			return $result;
		}

		public function get($lang, $name=NULL) {
			if ($name === NULL) {
				$result = $this->get($lang, 'symphony');
				foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
					// array_merge_recursive duplicates data inside 'about' (so there are two names of language instead of one string) :(.
					$temp = $this->get($lang, $extension);
					if (is_array($temp['about'])) $result['about'] = array_merge($result, $temp['about']);
					if (is_array($temp['dictionary'])) $result['dictionary'] = array_merge($result, $temp['dictionary']);
					if (is_array($temp['transliterations'])) $result['transliterations'] = array_merge($result, $temp['transliterations']);
				}
				return $result;
			}
			else if (strlen($name) < 1) return array();

			$file = ($name == 'symphony' ? $file = LANG."/lang.$lang.php" : EXTENSIONS."/$name/lang/lang.$lang.php");

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

			if ($isSymphony) $file = LANG.'/lang.'.$lang.'.php';
			else {
				$file = EXTENSIONS."/$name/lang/lang.$lang.php";
				if (!is_dir(EXTENSIONS."/$name/lang") && !General::realiseDirectory(EXTENSIONS."/$name/lang", $this->_Parent->Configuration->get('write_mode', 'directory'))) return false;
			}

			return General::writeFile($file, TranslationManager::toPHP($data), $this->_Parent->Configuration->get('write_mode', 'file'));
		}

		public function remove($lang, $name=NULL) {
			// TODO: remove requirement for 'symphony' in current language
			if (strlen($lang) < 1 || ($lang == $this->_Parent->Configuration->get('lang', 'symphony') && $name == 'symphony')) return false;

			if ($name === NULL) {
				if (!$this->remove($lang, 'symphony')) return false;
				foreach ($this->_Parent->ExtensionManager->listAll() as $extension => $about) {
					if (!$this->remove($lang, $extension)) return false;
				}
				return true;
			}

			if ($name == 'symphony') $file = LANG."/lang.$lang.php";
			else $file = EXTENSIONS."/$name/lang/lang.$lang.php";

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
					//'extensions/'.$name.'/lib', // TODO: extensions usually keep "alien" (non-symphony) code there, so there may be name duplication problems :(.
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

			$i = -1;
			if (!is_array($result) || !is_array($result[0])) $result[0] = array(); // Placeholder for warnings
			while ($tokens[++$i]) {
				if ($tokens[$i][0] != T_STRING || $tokens[$i][1] != '__') continue;
				$chunkLine = $tokens[$i][2];
				if ($tokens[++$i] != '(') continue;

				$depth = 1;
				$chunk = '';
				$warn = false;
				while ($tokens[++$i]) {
					if (is_array($tokens[$i])) {
						if ($tokens[$i][0] == T_CONSTANT_ENCAPSED_STRING) { // like: 'some value' or "some value"
							$chunk .= $tokens[$i][1];
							$str = trim(str_replace('\\'.$tokens[$i][1]{0}, $tokens[$i][1]{0}, $tokens[$i][1]), $tokens[$i][1]{0}); // constant strings are tokenized with qute included
							// TODO: unescape $str, so things like "foo\'s" will be properly parsed into "foo's"
							//eval('$str = '.$tokens[$i][1].';');
							$result[$str][$path][] = $tokens[$i][2];
						}
						else if ($tokens[$i][0] == T_START_HEREDOC) { // like <<<END some value END
							$str = '';
							$line = $tokens[$i][2];
							while (!is_array($tokens[++$i]) || $tokens[$i][0] != T_END_HEREDOC) {
								if (is_array($tokens[$i])) $str .= $tokens[$i][1];
								else $str .= $tokens[$i];
							}
							$result[$str][$path][] = $line;
							$warn = true; // Warn about using parsable strings for translatable data
							$chunk .= $str;
						}
						else {
							$chunk .= $tokens[$i][1];
						}
					}
					else if ($tokens[$i] == '(') {
						$depth++;
						$chunk .= $tokens[$i];
						$warn = true;
					}
					else if ($tokens[$i] == ')') {
						$depth--;
						$chunk .= $tokens[$i];
						if ($depth <= 0) break 1;
					}
					else if ($tokens[$i] == '"') { // This starts things like: "some $value"
						$chunk .= $tokens[$i];
						if (!is_array($tokens[$i+1]) || $tokens[$i+1][0] != T_ENCAPSED_AND_WHITESPACE) continue;
						$str = '';
						$line = $tokens[$i+1][2];
						while ($tokens[++$i] != '"') {
							if (is_array($tokens[$i])) $str .= $tokens[$i][1];
							else $str .= $tokens[$i];
						}
						$result[$str][$path][] = $line;
						$warn = true; // Warn about using parsable strings for translatable data
						$chunk .= $str;
					}
					else if ($tokens[$i] == '.') {
						$chunk .= $tokens[$i];
						$warn = true;
					}
					else if ($tokens[$i] == ',' && $depth == 1) break 1;
					else $chunk .= $tokens[$i];
				}
				if ($warn) {
					$result[0][$chunk][$path][] = $chunkLine;
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