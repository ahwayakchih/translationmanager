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

			$xliff = new XMLElement('xliff');
			$xliff->setIncludeHeader(true);
			$xliff->setAttribute('version', '1.2');
			$xliff->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:1.2');

			$file = new XMLElement('file');
			$file->setAttribute('original', URL.'/symphony/extension/translationmanager/edit/'.$lang.($extension ? '/'.$extension : ''));
			$file->setAttribute('source-language', 'en');
			$file->setAttribute('target-language', $lang);
			$file->setAttribute('datatype', 'x-symphony');
			$file->setAttribute('xml:space', 'preserve');
			if ($extension) $file->setAttribute('product-name', $extension);
			// TODO: load version of extension
			//if ($extension) $file->setAttribute('product-version', $extension);
			// TODO: convert date to format specified in http://docs.oasis-open.org/xliff/v1.2/os/xliff-core.html#date
			if ($translation['about']['release-date']) $file->setAttribute('date', $translation['about']['release-date']);
			if ($translation['about']['name']) $file->setAttribute('category', $translation['about']['name']);

			$header = new XMLElement('header');

			$tool = new XMLElement('tool');
			$tool->setAttribute('tool-id', 'tm');
			$tool->setAttribute('tool-name', 'Symphony - Translation Manager');
			$tool->setAttribute('tool-version', '0.9');
			$header->appendChild($tool);

			if (is_array($translation['about']['author'])) {
				$group = new XMLElement('phase-group');
				$appended = 0;
				if (is_string($translation['about']['author']['name'])) {
					$temp = $translation['about']['author'];
					$translation['about']['author'][$temp['name']] = $temp;
				}
				foreach ($translation['about']['author'] as $name => $author) {
					if (!$author['name']) continue;
					$phase = new XMLElement('phase');
					$phase->setAttribute('phase-name', $author['name']);
					$phase->setAttribute('phase-process', 'translation');
					$phase->setAttribute('tool-id', 'tm');
					if ($author['release-date']) $phase->setAttribute('date', $author['release-date']);
					if ($author['name']) $phase->setAttribute('contact-name', $author['name']);
					if ($author['email']) $phase->setAttribute('contact-email', $author['email']);
					if ($author['website']) $phase->setAttribute('company-name', $author['website']);
					$group->appendChild($phase);
					$appended++;
				}
				if ($appended) $header->appendChild($group);
			}

			$file->appendChild($header);
			$body = new XMLElement('body');

			$group = new XMLElement('group');
			$group->setAttribute('resname', 'dictionary');

			$translated = array_filter($translation['dictionary'], 'trim');
			foreach ($translated as $k => $v) {
				$unit = new XMLElement('trans-unit');
				$unit->setAttribute('id', md5($k));
				$unit->appendChild(new XMLElement('source', General::sanitize($k)));
				$unit->appendChild(new XMLElement('target', General::sanitize($v), array('state' => 'translated')));
				$group->appendChild($unit);
			}
			
			$missing = array_diff_key($default['dictionary'], $translation['dictionary']);
			foreach ($missing as $k => $v) {
				$unit = new XMLElement('trans-unit');
				$unit->setAttribute('id', md5($k));
				$unit->appendChild(new XMLElement('source', General::sanitize($k)));
				$unit->appendChild(new XMLElement('target', '', array('state' => 'new')));
				$group->appendChild($unit);
			}

			$obsolete = array_diff_key($translation['dictionary'], $default['dictionary']);
			foreach ($obsolete as $k => $v) {
				$unit = new XMLElement('trans-unit');
				$unit->setAttribute('id', md5($k));
				$unit->appendChild(new XMLElement('source', General::sanitize($k)));
				$unit->appendChild(new XMLElement('target', General::sanitize($v), array('state' => 'x-obsolete')));
				$group->appendChild($unit);
			}
			$body->appendChild($group);

			$group = new XMLElement('group');
			$group->setAttribute('resname', 'transliterations');

			if (is_array($translation['transliterations']) && !empty($translation['transliterations'])) {
				foreach ($translation['transliterations'] as $k => $v) {
					$unit = new XMLElement('trans-unit');
					$unit->setAttribute('id', md5($k));
					$unit->appendChild(new XMLElement('source', General::sanitize($k)));
					$unit->appendChild(new XMLElement('target', General::sanitize($v), array('state' => 'translated')));
					$group->appendChild($unit);
				}
			}
			else if ($extension == 'symphony') {
				foreach (TranslationManager::defaultTransliterations() as $k => $v) {
					$unit = new XMLElement('trans-unit');
					$unit->setAttribute('id', md5($k));
					$unit->appendChild(new XMLElement('source', General::sanitize($k)));
					$unit->appendChild(new XMLElement('target', General::sanitize($v), array('state' => 'new')));
					$group->appendChild($unit);
				}
			}
			$body->appendChild($group);

			$file->appendChild($body);
			$xliff->appendChild($file);

			$result = $xliff->generate(true);

			if (!empty($result)) {
				header('Content-Type: text/xml; charset=utf-8');
				header('Content-Disposition: attachment; filename="'.$extension.'-lang.'.$lang.'.xliff"');
				header("Content-Description: File Transfer");
				header("Cache-Control: no-cache, must-revalidate");
				header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
				echo $result;
				exit();
			}
		}
	}

?>