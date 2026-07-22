<?php
require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'ariston')) {
    echo __('Vous n\'Ãªtes pas autorisÃ© Ã  effectuer cette action', __FILE__);
    die();
}

$result = json_decode(file_get_contents('php://input'), true);
log::add('ariston', 'debug', 'CallBack from ariston daemon: ' . json_encode($result));

if (!is_array($result)) {
    die();
}

if ($result['FUNC'] == 'getDatas') {
    $eqId = $result['eqId'];
    $data = $result['data'];
    log::add('ariston', 'debug', 'Event sur Cmds : ' . json_encode($data));

    $eqLogic = ariston::byId($eqId);
    if (!is_object($eqLogic)) {
        log::add('ariston', 'error', 'Ãquipement introuvable avec l\'ID: ' . $eqId);
        return;
    }

    $modeMap = array(
        0 => 'Eté',
        1 => 'Hiver',
        2 => 'Chauffage seul',
        3 => 'Climatisation',
        4 => 'Clim seul',
        5 => 'Arrêt',
        6 => 'Vacances',
        7 => 'Programme',
    );

    if (isset($data['zones']) && is_array($data['zones'])) {
        foreach ($data['zones'] as $zone) {
            $zoneNum = intval($zone['num']);
            $eqLogic->createZoneCmds($zoneNum);

            $roomTemp = $eqLogic->getCmd(null, 'zone' . $zoneNum . '_room_temp');
            if (is_object($roomTemp) && isset($zone['room_temp'])) {
                $roomTemp->event(round(floatval($zone['room_temp']), 1));
            }
            $roomSetpoint = $eqLogic->getCmd(null, 'zone' . $zoneNum . '_room_setpoint');
            if (is_object($roomSetpoint) && isset($zone['room_setpoint'])) {
                $roomSetpoint->event(round(floatval($zone['room_setpoint']), 1));
            }
            $chOn = $eqLogic->getCmd(null, 'zone' . $zoneNum . '_ch_on');
            if (is_object($chOn) && isset($zone['ch_on'])) {
                $chOn->event($zone['ch_on'] ? 1 : 0);
            }
        }
    }

    $cmds = $eqLogic->getCmd('info');
    foreach ($cmds as $cmd) {
        switch ($cmd->getLogicalId()) {
            case 'online':
                $cmd->event(isset($data['online']) ? intval($data['online']) : 1);
                break;
            case 'flame':
                if (isset($data['flame'])) $cmd->event($data['flame'] ? 1 : 0);
                break;
            case 'mode':
                if (isset($data['mode'])) {
                    $modeVal = $data['mode'];
                    $cmd->event(isset($modeMap[$modeVal]) ? $modeMap[$modeVal] : strval($modeVal));
                }
                break;
            case 'ch_flow_temp':
                if (isset($data['ch_flow_temp'])) $cmd->event(round(floatval($data['ch_flow_temp']), 1));
                break;
            case 'ch_setpoint':
                if (isset($data['ch_setpoint'])) $cmd->event(round(floatval($data['ch_setpoint']), 1));
                break;
            case 'room_temp':
                if (isset($data['room_temp'])) $cmd->event(round(floatval($data['room_temp']), 1));
                break;
            case 'room_setpoint':
                if (isset($data['room_setpoint'])) $cmd->event(round(floatval($data['room_setpoint']), 1));
                break;
            case 'ch_on':
                if (isset($data['ch_on'])) $cmd->event($data['ch_on'] ? 1 : 0);
                break;
            case 'dhw_temp':
                if (isset($data['dhw_temp'])) $cmd->event(round(floatval($data['dhw_temp']), 1));
                break;
            case 'dhw_setpoint':
                if (isset($data['dhw_setpoint'])) $cmd->event(round(floatval($data['dhw_setpoint']), 1));
                break;
            case 'dhw_on':
                if (isset($data['dhw_on'])) $cmd->event($data['dhw_on'] ? 1 : 0);
                break;
            case 'pressure':
                if (isset($data['pressure'])) $cmd->event(round(floatval($data['pressure']), 2));
                break;
            case 'modulation':
                if (isset($data['modulation'])) $cmd->event(round(floatval($data['modulation']), 1));
                break;
            case 'outside_temp':
                if (isset($data['outside_temp'])) $cmd->event(round(floatval($data['outside_temp']), 1));
                break;
            case 'error_code':
                if (isset($data['error_code'])) $cmd->event(strval($data['error_code']));
                break;
            case 'errors_count':
                if (isset($data['errors_count'])) $cmd->event(intval($data['errors_count']));
                break;
            case 'signal_strength':
                if (isset($data['signal_strength'])) $cmd->event(intval($data['signal_strength']));
                break;
            case 'ch_return_temp':
                if (isset($data['ch_return_temp'])) $cmd->event(round(floatval($data['ch_return_temp']), 1));
                break;
            case 'holiday':
                if (isset($data['holiday'])) $cmd->event($data['holiday'] ? 1 : 0);
                break;
            case 'ch_gas_today':
                if (isset($data['ch_gas_today'])) $cmd->event(round(floatval($data['ch_gas_today']), 2));
                break;
            case 'ch_elec_today':
                if (isset($data['ch_elec_today'])) $cmd->event(round(floatval($data['ch_elec_today']), 2));
                break;
            case 'ch_energy_today':
                if (isset($data['ch_energy_today'])) $cmd->event(round(floatval($data['ch_energy_today']), 2));
                break;
            case 'dhw_gas_today':
                if (isset($data['dhw_gas_today'])) $cmd->event(round(floatval($data['dhw_gas_today']), 2));
                break;
            case 'dhw_elec_today':
                if (isset($data['dhw_elec_today'])) $cmd->event(round(floatval($data['dhw_elec_today']), 2));
                break;
            case 'dhw_energy_today':
                if (isset($data['dhw_energy_today'])) $cmd->event(round(floatval($data['dhw_energy_today']), 2));
                break;
            default:
                continue 2;
        }
        log::add('ariston', 'debug', 'Commande mise Ã  jour: ' . $cmd->getName());
    }
}

if ($result['FUNC'] == 'plants') {
    $plants = $result['data'];
    log::add('ariston', 'info', 'Appareils dÃ©tectÃ©s : ' . json_encode($plants));
    foreach ($plants as $plant) {
        $gwId = $plant['gw'] ?? '';
        if (empty($gwId)) continue;
        $eqLogic = eqLogic::byLogicalId($gwId, 'ariston');
        if (!is_object($eqLogic)) {
            $eqLogic = new ariston();
            $eqLogic->setLogicalId($gwId);
            $eqLogic->setName($plant['name'] ?? ('Ariston ' . $gwId));
            $eqLogic->setEqType_name('ariston');
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setConfiguration('gw', $gwId);
            $eqLogic->save();
        }
    }
}
