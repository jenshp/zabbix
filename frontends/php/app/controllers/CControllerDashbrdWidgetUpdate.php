<?php
/*
** Zabbix
** Copyright (C) 2001-2017 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/../../include/blocks.inc.php';

class CControllerDashbrdWidgetUpdate extends CController {

	private $widgets;

	public function __construct() {
		parent::__construct();

		$this->widgets = [];
	}

	protected function checkInput() {
		$fields = [
			'dashboardid' =>	'required|db dashboard.dashboardid',
			'widgets' =>		'required|array',
			'save' =>			'required|in '.implode(',', [WIDGET_CONFIG_DONT_SAVE, WIDGET_CONFIG_DO_SAVE])
		];

		$ret = $this->validateInput($fields);

		if ($ret) {
			/*
			 * @var array  $widgets
			 * @var string $widget[]['widgetid']
			 * @var array  $widget[]['pos']             (optional)
			 * @var int    $widget[]['pos']['row']
			 * @var int    $widget[]['pos']['col']
			 * @var int    $widget[]['pos']['height']
			 * @var int    $widget[]['pos']['width']
			 * @var array  $widget[]['fields']          (optional)
			 * @var string $widget[]['fields']['type']
			 * @var string $widget[]['fields'][<name>]  (optional)
			 */
			foreach ($this->getInput('widgets') as $index => $widget) {
				if (array_key_exists('pos', $widget)) {
					foreach (['row', 'col', 'height', 'width'] as $field) {
						if (!array_key_exists($field, $widget['pos'])) {
							error(_s('Invalid parameter "%1$s": %2$s.', 'widgets['.$index.'][pos]',
								_s('the parameter "%1$s" is missing', $field)
							));
							$ret = false;
						}
					}
				}

				if (array_key_exists('fields', $widget)) {
					if (!array_key_exists('type', $widget['fields'])) {
						error(_s('Invalid parameter "%1$s": %2$s.', 'widgets['.$index.'][fields]',
							_s('the parameter "%1$s" is missing', 'type')
						));
						$ret = false;
						break;
					}

					$widget['form'] = CWidgetConfig::getForm($widget['fields']);
					unset($widget['fields']);

					if (($errors = $widget['form']->validate()) !== []) {
						// Add widget name to each error message.
						foreach ($errors as $key => $error) {
							error(_s("Error in widget (id='%s'): %s.", $widget['widgetid'], $error)); // TODO VM: (?) improve error message
						}

						$ret = false;
					}
				}
				$this->widgets[] = $widget;
			}
		}

		if (!$ret) {
			$output = [];
			if (($messages = getMessages()) !== null) {
				// TODO AV: "errors" => "messages"
				$output['errors'] = $messages->toString();
			}
			$this->setResponse(new CControllerResponseData(['main_block' => CJs::encodeJson($output)]));
		}

		return $ret;
	}

	protected function checkPermissions() {
		return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
	}

	protected function doAction() {
		$return = [];

		if ($this->getInput('save') == WIDGET_CONFIG_DO_SAVE) {
			$upd_dashboard = [
				'dashboardid' => $this->getInput('dashboardid'),
				'widgets' => []
			];

			foreach ($this->widgets as $widget) {
				$upd_widget = [];
				if (array_key_exists('widgetid', $widget)) {
					$upd_widget['widgetid'] = $widget['widgetid'];
				}

				if (array_key_exists('pos', $widget)) {
					$upd_widget['row'] = $widget['pos']['row'];
					$upd_widget['col'] = $widget['pos']['col'];
					$upd_widget['height'] = $widget['pos']['height'];
					$upd_widget['width'] = $widget['pos']['width'];
				}

				if (array_key_exists('form', $widget)) {
					$upd_widget += $this->prepareFields($widget['form']);
				}

				$upd_dashboard['widgets'][] = $upd_widget;
			}

			$result = (bool) API::Dashboard()->update([$upd_dashboard]);

			if ($result) {
				$return['messages'] = makeMessageBox(true, [], _('Dashboard updated'))->toString();
			}
			else {
				// TODO AV: improve error messages
				if (!hasErrorMesssages()) {
					error(_('Failed to update dashboard')); // In case of unknown error
				}
				$return['errors'] = getMessages()->toString();
			}
		}
		$this->setResponse(new CControllerResponseData(['main_block' => CJs::encodeJson($return)]));
	}

	/**
	 * Prepares widget fields for saving.
	 *
	 * @param CWidgetForm $form  form object with widget fields
	 *
	 * @return array  With keys 'type', 'name', 'fields'
	 */
	protected function prepareFields($form) {
		$widget = ['fields' => []];

		foreach ($form->getFields() as $field) {
			$name = $field->getName();

			switch ($name) {
				case 'type':
				case 'name':
					$widget[$name] = $field->getValue();
					break;

				default:
					$save_type = $field->getSaveType();

					$widget_field = [
						'type' => $save_type,
						'name' => $field->getName()
					];
					$widget_field[CWidgetConfig::getApiFieldKey($save_type)] = $field->getValue();

					$widget['fields'][] = $widget_field;
			}
		}

		return $widget;
	}
}
