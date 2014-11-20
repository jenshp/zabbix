<?php
/*
** Zabbix
** Copyright (C) 2001-2014 Zabbix SIA
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


class C18ImportConverter extends CConverter {

	public function convert($value) {
		$content = $value['zabbix_export'];

		$content['version'] = '2.0';
		$content = $this->convertTime($content);

		$content = $this->convertHosts($content);
		$content = $this->convertGroups($content);

		$value['zabbix_export'] = $content;

		return $value;
	}

	/**
	 * Convert the date and time elements.
	 *
	 * @param array $content
	 *
	 * @return array
	 */
	protected function convertTime(array $content) {
		list($day, $month, $year) = explode('.', $content['date']);
		list($hours, $minutes) = explode('.', $content['time']);
		$content['date'] = date('Y-m-d\TH:i:s\Z', mktime($hours, $minutes, 0, $month, $day, $year));

		unset($content['time']);

		return $content;
	}

	/**
	 * Convert host elements.
	 *
	 * @param array $content
	 *
	 * @return array
	 */
	protected function convertHosts(array $content) {
		if (!isset($content['hosts'])) {
			return $content;
		}

		$content = $this->mergeToRoot($content['hosts'], $content, 'groups');
		foreach ($content['hosts'] as &$host) {
			$host = $this->renameKey($host, 'name', 'host');
			$host = $this->convertHostInterfaces($host);
			$host = $this->convertHostProfiles($host);

			unset($host['groups']);
			unset($host['useip']);
			unset($host['ip']);
			unset($host['dns']);
			unset($host['port']);
			unset($host['ipmi_ip']);
			unset($host['ipmi_port']);
			unset($host['host_profile']);
			unset($host['host_profiles_ext']);
		}
		unset($host);

		return $content;
	}

	/**
	 * Create host interfaces from the host properties and items and add them to the "host" element.
	 *
	 * @param array $host
	 *
	 * @return array
	 */
	protected function convertHostInterfaces(array $host) {
		$interfaces = array();
		$i = 0;

		// create an agent interface from the host properties
		if (isset($host['useip']) && isset($host['ip']) && isset($host['dns']) && isset($host['port'])) {
			$agentInterface = array(
				'type' => INTERFACE_TYPE_AGENT,
				'useip' => $host['useip'],
				'ip' => $host['ip'],
				'dns' => $host['dns'],
				'port' => $host['port'],
				'default' => INTERFACE_PRIMARY,
				'interface_ref' => 'if'.$i
			);
			$interfaces[] = $agentInterface;
			$i++;
		}

		$hasIpmiItem = false;
		$snmpItems = array();

		if (isset($host['items'])) {
			foreach ($host['items'] as $item) {
				if (!isset($item['type'])) {
					continue;
				}

				if ($item['type'] == ITEM_TYPE_IPMI) {
					$hasIpmiItem = true;
				}
				if ($item['type'] == ITEM_TYPE_SNMPV1 || $item['type'] == ITEM_TYPE_SNMPV2C || $item['type'] == ITEM_TYPE_SNMPV3) {
					$snmpItems[] = $item;
				}
			}

			// if a least one IPMI item exists on a host, create an IPMI interface
			if ($hasIpmiItem) {
				$ipmiInterface = array(
					'type' => INTERFACE_TYPE_IPMI,
					'useip' => INTERFACE_USE_IP,
					'ip' => ((isset($host['ipmi_ip']) && $host['ipmi_ip'] !== '') ? $host['ipmi_ip'] : $host['ip']),
					'dns' => '',
					'port' => $host['ipmi_port'],
					'default' => INTERFACE_PRIMARY,
					'interface_ref' => 'if'.$i
				);
				$interfaces[] = $ipmiInterface;
				$i++;
			}

			// if SNMP item exist, create an SNMP interface for each SNMP item port.
			if ($snmpItems) {
				$snmpInterfaces = array();
				foreach ($snmpItems as $item) {
					if (!isset($item['snmp_port']) || isset($snmpInterfaces[$item['snmp_port']])) {
						continue;
					}

					$snmpInterface = array(
						'type' => INTERFACE_TYPE_SNMP,
						'useip' => $host['useip'],
						'ip' => $host['ip'],
						'dns' => $host['dns'],
						'port' => $item['snmp_port'],
						'default' => (count($snmpInterfaces)) ? INTERFACE_SECONDARY : INTERFACE_PRIMARY,
						'interface_ref' => 'if'.$i
					);
					$snmpInterfaces[$item['snmp_port']] = $snmpInterface;
					$interfaces[] = $snmpInterface;
					$i++;
				}
			}
		}

		if ($interfaces) {
			$host['interfaces'] = $interfaces;
		}

		// map items to new interfaces
		if (isset($host['items'])) {
			foreach ($host['items'] as &$item) {
				if (!isset($item['type'])) {
					continue;
				}

				$interfaceType = itemTypeInterface($item['type']);
				switch ($interfaceType) {
					case INTERFACE_TYPE_AGENT:
					case INTERFACE_TYPE_ANY:
						$item['interface_ref'] = $agentInterface['interface_ref'];

						break;
					case INTERFACE_TYPE_IPMI:
						$item['interface_ref'] = $ipmiInterface['interface_ref'];

						break;
					case INTERFACE_TYPE_SNMP:
						if (isset($item['snmp_port'])) {
							$item['interface_ref'] = $snmpInterfaces[$item['snmp_port']]['interface_ref'];
						}

						break;
				}
			}
			unset($item);
		}

		return $host;
	}

	/**
	 * Convert host "host_profile" and "host_profiles_ext" elements and calculate "inventory_mode".
	 *
	 * @param array $host
	 *
	 * @return array
	 */
	protected function convertHostProfiles(array $host) {
		$hasProfileData = (isset($host['host_profile']) || isset($host['host_profiles_ext']));

		// if the host contains inventory data, set inventory to mode to manual, otherwise disable it
		$host['inventory_mode'] = ($hasProfileData) ? HOST_INVENTORY_MANUAL : HOST_INVENTORY_DISABLED;

		if (!$hasProfileData) {
			return $host;
		}

		// rename and merge profile fields
		$inventory = array();
		if (isset($host['host_profile'])) {
			foreach ($host['host_profile'] as $key => $value) {
				$newKey = $this->getNewProfileName($key);
				$inventory[($newKey !== null) ? $newKey : $key] = $value;
			}
		}

		if (isset($host['host_profiles_ext'])) {
			foreach ($host['host_profiles_ext'] as $key => $value) {
				$newKey = $this->getNewProfileName($key);
				$key = ($newKey !== null) ? $newKey : $key;

				// if renaming results in a duplicate inventory field, concatenate them
				// this is the case with "notes" and "device_notes"
				if (isset($inventory[$newKey])) {
					$inventory[$newKey] .= "\r\n\r\n".$value;
				}
				else {
					$inventory[$key] = $value;
				}
			}
		}

		$host['inventory'] = $inventory;

		return $host;
	}

	/**
	 * Map an old profile key name to the new inventory key name.
	 *
	 * @param string $oldName
	 *
	 * @return string|null
	 */
	protected function getNewProfileName($oldName) {
		$map = array(
			'devicetype' => 'type',
			'serialno' => 'serialno_a',
			'macaddress' => 'macaddress_a',
			'hardware' => 'hardware_full',
			'software' => 'software_full',
			'device_type' => 'type_full',
			'device_alias' => 'alias',
			'device_os' => 'os_full',
			'device_os_short' => 'os_short',
			'device_serial' => 'serialno_b',
			'device_tag' => 'asset_tag',
			'ip_macaddress' => 'macaddress_b',
			'device_hardware' => 'hardware',
			'device_software' => 'software',
			'device_app_01' => 'software_app_a',
			'device_app_02' => 'software_app_b',
			'device_app_03' => 'software_app_c',
			'device_app_04' => 'software_app_d',
			'device_app_05' => 'software_app_e',
			'device_chassis' => 'chassis',
			'device_model' => 'model',
			'device_hw_arch' => 'hw_arch',
			'device_vendor' => 'vendor',
			'device_contract' => 'contract_number',
			'device_who' => 'installer_name',
			'device_status' => 'deployment_status',
			'device_url_1' => 'url_a',
			'device_url_2' => 'url_b',
			'device_url_3' => 'url_c',
			'device_networks' => 'host_networks',
			'ip_subnet_mask' => 'host_netmask',
			'ip_router' => 'host_router',
			'oob_subnet_mask' => 'oob_netmask',
			'date_hw_buy' => 'date_hw_purchase',
			'site_street_1' => 'site_address_a',
			'site_street_2' => 'site_address_b',
			'site_street_3' => 'site_address_c',
			'poc_1_phone_1' => 'poc_1_phone_a',
			'poc_1_phone_2' => 'poc_1_phone_b',
			'poc_2_phone_1' => 'poc_2_phone_a',
			'poc_2_phone_2' => 'poc_2_phone_b',
			'device_notes' => 'notes',
		);

		return (isset($map[$oldName])) ? $map[$oldName] : null;
	}

	/**
	 * Convert groups merged into the "groups" element.
	 *
	 * @param array $content
	 *
	 * @return array
	 */
	protected function convertGroups(array $content) {
		if (!isset($content['groups'])) {
			return $content;
		}

		$content['groups'] = $this->wrapChildren($content['groups'], 'name');
		$content['groups'] = array_values(zbx_toHash($content['groups'], 'name'));

		return $content;
	}

	protected function mergeToRoot(array $source, array $target, $key) {
		$values = (isset($target[$key])) ? $target[$key] : array();

		foreach ($source as $sourceItem) {
			if (!isset($sourceItem[$key])) {
				continue;
			}

			foreach ($sourceItem[$key] as $value) {
				$values[] = $value;
			}

		}

		if ($values) {
			$target[$key] = $values;
		}

		return $target;
	}

	protected function wrapChildren($array, $key) {
		$result = array();

		foreach ($array as $content) {
			$result[] = array(
				$key => $content[0]
			);
		}

		return $result;
	}

	protected function renameKey(array $array, $source, $target) {
		if (!isset($array[$source])) {
			return $array;
		}

		$array[$target] = $array[$source];
		unset($array[$source]);

		return $array;
	}

}
