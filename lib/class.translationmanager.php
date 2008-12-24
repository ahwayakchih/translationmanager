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

		public function defaultDictionary($name=NULL) {
			// TODO: use cache and clear it whenever some extension is added, removed or updated

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
				$temp = $this->_Parent->ExtensionManager->listAll();
				$names = array_keys($temp);
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

			$strings = array();
			$strings[] = array(); // Warnings placeholder
			foreach ($paths as $path) {
				$files = General::listStructure(DOCROOT."/$path", array('php', 'tpl'), false, 'asc');
				if (empty($files['filelist'])) continue;
				foreach ($files['filelist'] as $file) {
					$temp = TranslationManager::__findStrings(file_get_contents(DOCROOT."/$path/$file"));
					if (is_array($temp['warning'])) {
						foreach ($temp['warning'] as $string) {
							$strings[0][$string][$file]++;
						}
						unset($temp['warning']);
					}
					foreach ($temp as $string) {
						$strings[$string][$file]++;
					}
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

		private static function __findStrings($data) {
			$result = array();
			TranslationManager::__getStrings($result, $data);
			return $result;
		}

		private static function __getStrings(&$result, $data) {
			if (preg_match_all('/(?<!function\s)__\(((?:[^()]++|\((?1)\))*)\)/', $data, $found, PREG_PATTERN_ORDER)) {
				foreach ($found[1] as $i) {
					if ($i{0} != "'" && $i{0} != '"') {
						if ($i{0} == '$' && preg_match('/^\$[^s]+$/', $i)) {
							// it's probably just: __($variable)
							// TODO: check if $variable is not set in the same scope, like: $variable = ($check ? 'one' : 'two'); __($variable);
							//       in which case we could try to parse it
							$result['warning'][] = $i;
							continue;
						}
						else if ($i{0} == '(') {
							// Try to parse __(($check ? 'one' : 'two')) case
							if (preg_match('/^\(((?:[^()]++|\((?1)\))*)\)/', $i, $temp)) {
								$php = $temp[0];
								$result['warning'][] = $i;
								TranslationManager::__getStrings($result, str_replace($php, '', $i));
								TranslationManager::__safe_eval($result, $php);
								continue;
							}
						}

						$result['warning'][] = $i;
						TranslationManager::__getStrings($result, $i);
						continue;
					}

					if (preg_match('/'.$i{0}.'([^'.$i{0}.'\\\\]*(\\\\.[^'.$i{0}.'\\\\]*)*)'.$i{0}.'(\s*,\s*[\w\W]+|)/', $i, $s)) {
						$result[] = str_replace('\\'.$i{0}, $i{0}, $s[1]); // Unescape quotes or doublequotes, depending on which was used to put text into PHP

						if ($s[3]) TranslationManager::__getStrings($result, $s[3]);
					}
				}
			}
		}

		private static function __safe_eval(&$result, $php) {
			$dummy = array();
			if (preg_match_all('/\$[^\s]+/', $php, $temp, PREG_PATTERN_ORDER)) {
				for ($x = 0; $x < count($temp[0]); $x++) {
					$dummy[$x] = 0;
					$php = str_replace($temp[0][$x], '$dummy['.$x.']', $php);
				}
				eval('$result[] = '.$php.';');
				foreach ($dummy as $i => $v) {
					$dummy[$i] = 1;
				}
				eval('$result[] = '.$php.';');
				// TODO: try to run for all combinations of values? $dummy[0] = 0; $dummy[1] = 1; $dummy[2] = 0; etc...
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