<?php

	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(EXTENSIONS . '/translationmanager/lib/class.translationmanager.php');

	Class contentExtensionTranslationManagerIndex extends AdministrationPage{
		private $_tm;

		function __construct(&$parent){
			parent::__construct($parent);

			$this->_tm = new TranslationManager($parent);
		}

		function view(){
			$this->setPageType('table');
			$this->setTitle('Symphony &ndash; Translation Manager');
			$this->appendSubheading('Languages', Widget::Anchor('Create New', $this->_Parent->getCurrentPageURL().'edit/', 'Create new translation', 'create button'));

			$link = new XMLElement('link');
			$link->setAttributeArray(array('rel' => 'stylesheet', 'type' => 'text/css', 'media' => 'screen', 'href' => URL.'/extensions/translationmanager/assets/admin.css'));
			$this->addElementToHead($link, 500);
			$this->addScriptToHead(URL . '/extensions/translationmanager/assets/admin.js', 501);

			$default = $this->_tm->defaultDictionary();
			$translations = $this->_tm->listAll();
			$allextensions = $this->_Parent->ExtensionManager->listAll();
			$current = $this->_Parent->Configuration->get('lang', 'symphony');

			$warnings = array_shift($default);

			$aTableHead = array(
				array('Name', 'col'),
				array('Code', 'col'),
				array('Extensions (out of '.(count($allextensions)+1).')*', 'col', array('title' => 'Extensions, including Symphony')),
				array('Translated (out of '.count($default).')', 'col'),
				array('Obsolete', 'col'),
				array('Current', 'col'),
			);	

			$aTableBody = array();

			if(!is_array($translations) || empty($translations)){
				$aTableBody = array(Widget::TableRow(array(Widget::TableData(__('None Found.'), 'inactive', NULL, count($aTableHead)))));
			}
			else {
				foreach ($translations as $lang => $extensions) {
					$language = $this->_tm->get($lang);
					$translated = array_filter($language['dictionary'], 'trim');
					$missing = array_diff_key($default, $language['dictionary']);
					$obsolete = array_diff_key($language['dictionary'], $default);

					if (!$language['about']['name']) $language['about']['name'] = $lang;

					$td1 = Widget::TableData(Widget::Anchor($language['about']['name'], $this->_Parent->getCurrentPageURL().'edit/'.$lang.'/', $language['about']['name']));
					$td2 = Widget::TableData($lang);
					$td3 = Widget::TableData((string)count($extensions), NULL, NULL, NULL, array('title' => implode(',', $extensions)));
					$td4 = Widget::TableData(count($translated).' <small>('.floor((count($translated) - count($obsolete)) / count($default) * 100).'%)</small>');
					$td5 = Widget::TableData((string)count($obsolete));
					$td6 = Widget::TableData(($lang == $current ? 'Yes' : 'No'));

					$td1->appendChild(Widget::Input('items['.$lang.']', NULL, 'checkbox'));

					## Add a row to the body array, assigning each cell to the row
					$aTableBody[] = Widget::TableRow(array($td1, $td2, $td3, $td4, $td5, $td6));
				}
			}

			$table = Widget::Table(Widget::TableHead($aTableHead), NULL, Widget::TableBody($aTableBody));
			$this->Form->appendChild($table);

			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');

			$options = array(
				array(NULL, false, 'With Selected...'),
				array('delete', false, 'Delete'),
				array('switch', false, 'Make it current'),
			);

			$div->appendChild(Widget::Select('with-selected', $options));
			$div->appendChild(Widget::Input('action[apply]', 'Apply', 'submit'));

			$this->Form->appendChild($div);
		}

		function action() {
			if (!$_POST['action']['apply']) return;

			switch ($_POST['with-selected']) {
				case 'delete':
					if (is_array($_POST['items'])) {
						foreach ($_POST['items'] as $lang => $selected) {
							if ($selected == 'on') $this->delete($lang);
						}
					}
					break;
				case 'switch':
					if (is_array($_POST['items'])) {
						foreach ($_POST['items'] as $lang => $selected) {
							if ($selected == 'on' && $this->_tm->enable($lang)) {
								redirect(URL . '/symphony/extension/translationmanager/');
							}
						}
					}
					break;
			}
		}

		function delete($lang) {
			if ($lang == $this->_Parent->Configuration->get('lang', 'symphony'))
				$this->pageAlert('Cannot delete language in use. Please change language used by Symphony and try again.', AdministrationPage::PAGE_ALERT_ERROR);
			else if (!$this->_tm->remove($lang))
				$this->pageAlert('Failed to delete translation <code>'.$lang.'</code>. Please check file permissions or if it is not in use.', AdministrationPage::PAGE_ALERT_ERROR);
		}
	}

?>