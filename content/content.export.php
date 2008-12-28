<?php

	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(EXTENSIONS . '/translationmanager/lib/class.translationmanager.php');

	Class contentExtensionTranslationManagerExport extends AdministrationPage{
		private $_tm;

		function __construct(&$parent){
			parent::__construct($parent);

			$this->_tm = new TranslationManager($parent);
		}

		function view(){
			$lang = $this->_context[0];
			$extension = $this->_context[1];
			$isSymphony = (empty($extension) || $extension == 'symphony');

			if (strlen($lang) < 1) {
				$this->setPageType('form');
				$this->pageAlert('Language code is required.', AdministrationPage::PAGE_ALERT_ERROR);
				return;
			}

			$default = array(
				'about' => array(),
				'dictionary' => $this->_tm->defaultDictionary($extension),
				'transliterations' => array(),
			);

			$warnings = array_shift($default['dictionary']);

			$translation = $this->_tm->get($lang, $extension);
			if (empty($translation)) {
				$translation = array(
					'about' => array('name' => $lang),
					'dictionary' => array(),
					'transliterations' => array(),
				);
				// Try to find language name in other translations for selected language
				$others = $this->_tm->listExtensions($lang);
				foreach ($others as $t_ext) {
					$t_trans = $this->_tm->get($lang, $t_ext);
					if (!empty($t_trans['about']['name'])) {
						$translation['about']['name'] = $t_trans['about']['name'];
						break;
					}
				}
			}

			$dictionary = array();
			$translated = array_intersect_key(array_filter($translation['dictionary'], 'trim'), $default['dictionary']);
			$missing = array_diff_key($default['dictionary'], $translation['dictionary']);
			$obsolete = array_diff_key($translation['dictionary'], $default['dictionary']);
			if (is_array($translated) && count($translated) > 0) {
				$dictionary['%%%%%%TRANSLATED%%%%%%'] = '%%%%%%TRANSLATED%%%%%%';
				$dictionary += $translated;
			}
			if (is_array($missing) && count($missing) > 0) {
				$dictionary['%%%%%%MISSING%%%%%%'] = '%%%%%%MISSING%%%%%%';
				$dictionary += array_fill_keys(array_keys($missing), false);
			}
			if (is_array($obsolete) && count($obsolete) > 0) {
				$dictionary['%%%%%%OBSOLETE%%%%%%'] = '%%%%%%OBSOLETE%%%%%%';
				$dictionary += $obsolete;
			}

			if ((!is_array($translation['transliterations']) || empty($translation['transliterations'])) && $isSymphony) {
				$translation['transliterations'] = TranslationManager::defaultTransliterations();
			}

			$path = ($isSymphony ? TranslationManager::filePath($lang, 'symphony') : TranslationManager::filePath($lang, $extension));
			$file = basename($path);
			$path = dirname($path);
			$php = '<'."?php\n\n";
			$php .= <<<END
/*
	This is translation file for $extension.
	To make it available in Symphony, rename it $file and upload to $path directory on server.
*/

END;
			$php .= '$about = '.var_export($translation['about'], true).";\n\n";
			$php .= <<<END
/*
	Dictionary array contains translations for text used in Symphony.
	To add missing translations, simply scroll down to part of array marked as "// MISSING"
	and change each "false" value into something like "'New translation of original text'"
	(notice single quotes around translated text! Text has to be wrapped either by them or by doublequotes).
*/

END;
			$php .= '$dictionary = '.str_replace(' => ', " =>\n  ", preg_replace('/\n\s+\'%%%%%%(TRANSLATED|MISSING|OBSOLETE)%%%%%%\'\s+=>\s+\'%%%%%%(\1)%%%%%%\',\n/', "\n// \\1\n", var_export($dictionary, true))).";\n\n";
			$php .= <<<END
/*
	Transliterations are used to generate handles of entry fields and filenames.
	They specify which characters (or bunch of characters) have to be changed, and what should be put into their place when entry or file is saved.
	Transliterations are required only for translation of Symphony. They are not needed for extensions.
*/

END;
			$php .= '$transliterations = '.var_export($translation['transliterations'], true).";\n\n";
			$php .= '?>';

			if (!empty($php)) {
				header('Content-Type: application/x-php; charset=utf-8');
				header('Content-Disposition: attachment; filename="'.$extension.'-lang.'.$lang.'.php"');
				header("Content-Description: File Transfer");
				header("Cache-Control: no-cache, must-revalidate");
				header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
				echo trim($php);
				exit();
			}
		}
	}

?>