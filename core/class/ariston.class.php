<?php
require_once __DIR__ . '/../../../../core/php/core.inc.php';

class ariston extends eqLogic {

    public static function cronInterval($interval) {
        $valueCronTemp = config::byKey('cronchoice', 'ariston', 5);
        $valueCron = trim($valueCronTemp);
        if (!is_numeric($valueCron)) {
            throw new Exception(__('Veuillez vérifier la configuration du cron, valeur non attendue', __FILE__));
        }
        if (intval($valueCron) == intval($interval)) {
            self::getDatas();
        }
    }

    public static function cron() {
        self::cronInterval(1);
    }

    public static function cron5() {
        self::cronInterval(5);
    }

    public static function cron10() {
        self::cronInterval(10);
    }

    public static function cron15() {
        self::cronInterval(15);
    }

    public static function cron30() {
        self::cronInterval(30);
    }

    public static function cronHourly() {
        self::cronInterval(60);
    }

    public static function deamon_info() {
        $return = array();
        $return['log'] = 'ariston';
        $return['state'] = 'nok';
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid && function_exists('posix_getsid') && posix_getsid($pid)) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
            }
        }
        $return['launchable'] = 'ok';
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }
        $path = realpath(dirname(__FILE__) . '/../../resources/aristond');
        $cmd = system::getCmdPython3(__CLASS__) . "{$path}/aristond.py";
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
        $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, '57131');
        $cmd .= ' --sockethost 127.0.0.1';
        $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/ariston/core/php/jeeAriston.php';
        $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
        $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        $cmd .= ' --email ' . escapeshellarg(config::byKey('email', __CLASS__, ''));
        $cmd .= ' --password ' . escapeshellarg(config::byKey('password', __CLASS__, ''));
        log::add(__CLASS__, 'info', 'Lancement démon : ' . $cmd);
        $result = exec($cmd . ' >> ' . log::getPathToLog('ariston') . ' 2>&1 &');
        $i = 0;
        while ($i < 5) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 5) {
            log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
            return false;
        }
        message::removeAll(__CLASS__, 'unableStartDeamon');
        return true;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            system::kill($pid);
        }
        system::kill('aristond.py');
        system::fuserk(config::byKey('socketport', 'ariston', '57131'));
        sleep(1);
    }

    public static function socketConnection($value) {
        $port = intval(config::byKey('socketport', 'ariston', 57131));
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($socket);
        @socket_connect($socket, '127.0.0.1', $port);
        $read = null;
        $write = array($socket);
        $except = null;
        $result = socket_select($read, $write, $except, 5);
        if ($result === false || $result === 0) {
            log::add(__CLASS__, 'error', 'Timeout connexion daemon ariston (5s)');
            socket_close($socket);
            return;
        }
        socket_set_block($socket);
        socket_write($socket, $value, strlen($value));
        socket_close($socket);
    }

    public static function getDatas() {
        $email = config::byKey('email', 'ariston', '');
        $password = config::byKey('password', 'ariston', '');
        if (empty($email) || empty($password)) {
            throw new Exception(__('Veuillez renseigner votre email et mot de passe dans la configuration du plugin', __FILE__));
        }
        $eqLogics = eqLogic::byType('ariston');
        foreach ($eqLogics as $eqLogic) {
            $value = json_encode(array(
                'apikey' => jeedom::getApiKey('ariston'),
                'action' => 'getDatas',
                'eqId' => $eqLogic->getId(),
                'gw' => $eqLogic->getConfiguration('gw', '')
            ));
            self::socketConnection($value);
        }
    }

    public function preInsert() {
    }

    public function postInsert() {
        $this->createCmds();
    }

    public function preSave() {
    }

    public function postSave() {
        $this->createCmds();
    }

    public function preRemove() {
    }

    public function postRemove() {
    }

    public function createZoneCmds($zoneNum) {
        $roomTemp = $this->getCmd(null, 'zone' . $zoneNum . '_room_temp');
        if (!is_object($roomTemp)) {
            $roomTemp = new aristonCmd();
            $roomTemp->setEqLogic_id($this->getId());
            $roomTemp->setLogicalId('zone' . $zoneNum . '_room_temp');
        }
        $roomTemp->setName(__('Temp. ambiante', __FILE__) . ' - ' . __('Circuit', __FILE__) . ' ' . $zoneNum);
        $roomTemp->setType('info');
        $roomTemp->setSubType('numeric');
        $roomTemp->setUnite('°C');
        $roomTemp->setIsHistorized(1);
        $roomTemp->save();

        $roomSetpoint = $this->getCmd(null, 'zone' . $zoneNum . '_room_setpoint');
        if (!is_object($roomSetpoint)) {
            $roomSetpoint = new aristonCmd();
            $roomSetpoint->setEqLogic_id($this->getId());
            $roomSetpoint->setLogicalId('zone' . $zoneNum . '_room_setpoint');
        }
        $roomSetpoint->setName(__('Consigne', __FILE__) . ' - ' . __('Circuit', __FILE__) . ' ' . $zoneNum);
        $roomSetpoint->setType('info');
        $roomSetpoint->setSubType('numeric');
        $roomSetpoint->setUnite('°C');
        $roomSetpoint->setIsHistorized(1);
        $roomSetpoint->save();

        $chOn = $this->getCmd(null, 'zone' . $zoneNum . '_ch_on');
        if (!is_object($chOn)) {
            $chOn = new aristonCmd();
            $chOn->setEqLogic_id($this->getId());
            $chOn->setLogicalId('zone' . $zoneNum . '_ch_on');
        }
        $chOn->setName(__('Chauffage actif', __FILE__) . ' - ' . __('Circuit', __FILE__) . ' ' . $zoneNum);
        $chOn->setType('info');
        $chOn->setSubType('binary');
        $chOn->setIsHistorized(1);
        $chOn->save();

        $setSetpoint = $this->getCmd(null, 'zone' . $zoneNum . '_set_setpoint');
        if (!is_object($setSetpoint)) {
            $setSetpoint = new aristonCmd();
            $setSetpoint->setEqLogic_id($this->getId());
            $setSetpoint->setLogicalId('zone' . $zoneNum . '_set_setpoint');
        }
        $setSetpoint->setName(__('Définir consigne', __FILE__) . ' - ' . __('Circuit', __FILE__) . ' ' . $zoneNum);
        $setSetpoint->setType('action');
        $setSetpoint->setSubType('slider');
        $setSetpoint->setConfiguration('minValue', 5);
        $setSetpoint->setConfiguration('maxValue', 30);
        $setSetpoint->setIsHistorized(0);
        $setSetpoint->save();
    }

    public function createCmds() {

        $online = $this->getCmd(null, 'online');
        if (!is_object($online)) {
            $online = new aristonCmd();
            $online->setName(__('Connexion', __FILE__));
            $online->setEqLogic_id($this->getId());
            $online->setLogicalId('online');
            $online->setType('info');
            $online->setSubType('binary');
            $online->setIsHistorized(0);
            $online->save();
        }

        $flame = $this->getCmd(null, 'flame');
        if (!is_object($flame)) {
            $flame = new aristonCmd();
            $flame->setName(__('Flamme', __FILE__));
            $flame->setEqLogic_id($this->getId());
            $flame->setLogicalId('flame');
            $flame->setType('info');
            $flame->setSubType('binary');
            $flame->setIsHistorized(1);
            $flame->save();
        }

        $mode = $this->getCmd(null, 'mode');
        if (!is_object($mode)) {
            $mode = new aristonCmd();
            $mode->setName(__('Mode', __FILE__));
            $mode->setEqLogic_id($this->getId());
            $mode->setLogicalId('mode');
            $mode->setType('info');
            $mode->setSubType('string');
            $mode->setIsHistorized(0);
            $mode->save();
        }

        $chFlowTemp = $this->getCmd(null, 'ch_flow_temp');
        if (!is_object($chFlowTemp)) {
            $chFlowTemp = new aristonCmd();
            $chFlowTemp->setName(__('Temp. départ CH', __FILE__));
            $chFlowTemp->setEqLogic_id($this->getId());
            $chFlowTemp->setLogicalId('ch_flow_temp');
            $chFlowTemp->setType('info');
            $chFlowTemp->setSubType('numeric');
            $chFlowTemp->setUnite('°C');
            $chFlowTemp->setIsHistorized(1);
            $chFlowTemp->save();
        }

        $chSetpoint = $this->getCmd(null, 'ch_setpoint');
        if (!is_object($chSetpoint)) {
            $chSetpoint = new aristonCmd();
            $chSetpoint->setName(__('Consigne CH', __FILE__));
            $chSetpoint->setEqLogic_id($this->getId());
            $chSetpoint->setLogicalId('ch_setpoint');
            $chSetpoint->setType('info');
            $chSetpoint->setSubType('numeric');
            $chSetpoint->setUnite('°C');
            $chSetpoint->setIsHistorized(1);
            $chSetpoint->save();
        }

        $roomTemp = $this->getCmd(null, 'room_temp');
        if (!is_object($roomTemp)) {
            $roomTemp = new aristonCmd();
            $roomTemp->setName(__('Temp. ambiante', __FILE__));
            $roomTemp->setEqLogic_id($this->getId());
            $roomTemp->setLogicalId('room_temp');
            $roomTemp->setType('info');
            $roomTemp->setSubType('numeric');
            $roomTemp->setUnite('°C');
            $roomTemp->setIsHistorized(1);
            $roomTemp->save();
        }

        $roomSetpoint = $this->getCmd(null, 'room_setpoint');
        if (!is_object($roomSetpoint)) {
            $roomSetpoint = new aristonCmd();
            $roomSetpoint->setName(__('Consigne pièce', __FILE__));
            $roomSetpoint->setEqLogic_id($this->getId());
            $roomSetpoint->setLogicalId('room_setpoint');
            $roomSetpoint->setType('info');
            $roomSetpoint->setSubType('numeric');
            $roomSetpoint->setUnite('°C');
            $roomSetpoint->setIsHistorized(1);
            $roomSetpoint->save();
        }

        $chOn = $this->getCmd(null, 'ch_on');
        if (!is_object($chOn)) {
            $chOn = new aristonCmd();
            $chOn->setName(__('CH actif', __FILE__));
            $chOn->setEqLogic_id($this->getId());
            $chOn->setLogicalId('ch_on');
            $chOn->setType('info');
            $chOn->setSubType('binary');
            $chOn->setIsHistorized(1);
            $chOn->save();
        }

        $dhwTemp = $this->getCmd(null, 'dhw_temp');
        if (!is_object($dhwTemp)) {
            $dhwTemp = new aristonCmd();
            $dhwTemp->setName(__('Temp. ECS actuelle', __FILE__));
            $dhwTemp->setEqLogic_id($this->getId());
            $dhwTemp->setLogicalId('dhw_temp');
            $dhwTemp->setType('info');
            $dhwTemp->setSubType('numeric');
            $dhwTemp->setUnite('°C');
            $dhwTemp->setIsHistorized(1);
            $dhwTemp->save();
        }

        $dhwSetpoint = $this->getCmd(null, 'dhw_setpoint');
        if (!is_object($dhwSetpoint)) {
            $dhwSetpoint = new aristonCmd();
            $dhwSetpoint->setName(__('Consigne ECS', __FILE__));
            $dhwSetpoint->setEqLogic_id($this->getId());
            $dhwSetpoint->setLogicalId('dhw_setpoint');
            $dhwSetpoint->setType('info');
            $dhwSetpoint->setSubType('numeric');
            $dhwSetpoint->setUnite('°C');
            $dhwSetpoint->setIsHistorized(1);
            $dhwSetpoint->save();
        }

        $dhwOn = $this->getCmd(null, 'dhw_on');
        if (!is_object($dhwOn)) {
            $dhwOn = new aristonCmd();
            $dhwOn->setName(__('ECS actif', __FILE__));
            $dhwOn->setEqLogic_id($this->getId());
            $dhwOn->setLogicalId('dhw_on');
            $dhwOn->setType('info');
            $dhwOn->setSubType('binary');
            $dhwOn->setIsHistorized(1);
            $dhwOn->save();
        }

        $pressure = $this->getCmd(null, 'pressure');
        if (!is_object($pressure)) {
            $pressure = new aristonCmd();
            $pressure->setName(__('Pression', __FILE__));
            $pressure->setEqLogic_id($this->getId());
            $pressure->setLogicalId('pressure');
            $pressure->setType('info');
            $pressure->setSubType('numeric');
            $pressure->setUnite('bar');
            $pressure->setIsHistorized(1);
            $pressure->save();
        }

        $modulation = $this->getCmd(null, 'modulation');
        if (!is_object($modulation)) {
            $modulation = new aristonCmd();
            $modulation->setName(__('Modulation', __FILE__));
            $modulation->setEqLogic_id($this->getId());
            $modulation->setLogicalId('modulation');
            $modulation->setType('info');
            $modulation->setSubType('numeric');
            $modulation->setUnite('%');
            $modulation->setIsHistorized(1);
            $modulation->save();
        }

        $outsideTemp = $this->getCmd(null, 'outside_temp');
        if (!is_object($outsideTemp)) {
            $outsideTemp = new aristonCmd();
            $outsideTemp->setName(__('Temp. extérieure', __FILE__));
            $outsideTemp->setEqLogic_id($this->getId());
            $outsideTemp->setLogicalId('outside_temp');
            $outsideTemp->setType('info');
            $outsideTemp->setSubType('numeric');
            $outsideTemp->setUnite('°C');
            $outsideTemp->setIsHistorized(1);
            $outsideTemp->save();
        }

        $errorCode = $this->getCmd(null, 'error_code');
        if (!is_object($errorCode)) {
            $errorCode = new aristonCmd();
            $errorCode->setName(__('Code erreur', __FILE__));
            $errorCode->setEqLogic_id($this->getId());
            $errorCode->setLogicalId('error_code');
            $errorCode->setType('info');
            $errorCode->setSubType('string');
            $errorCode->setIsHistorized(0);
            $errorCode->save();
        }

            // Signal strength (WiFi)
            $signalStrength = $this->getCmd(null, 'signal_strength');
            if (!is_object($signalStrength)) {
                $signalStrength = new aristonCmd();
                $signalStrength->setLogicalId('signal_strength');
                $signalStrength->setEqLogic_id($this->getId());
            }
            $signalStrength->setName(__('Signal WiFi', __FILE__));
            $signalStrength->setType('info');
            $signalStrength->setSubType('numeric');
            $signalStrength->setUnite('%');
            $signalStrength->setIsHistorized(0);
            $signalStrength->save();

            // CH return temp
            $chReturnTemp = $this->getCmd(null, 'ch_return_temp');
            if (!is_object($chReturnTemp)) {
                $chReturnTemp = new aristonCmd();
                $chReturnTemp->setLogicalId('ch_return_temp');
                $chReturnTemp->setEqLogic_id($this->getId());
            }
            $chReturnTemp->setName(__('Temp. retour CH', __FILE__));
            $chReturnTemp->setType('info');
            $chReturnTemp->setSubType('numeric');
            $chReturnTemp->setUnite('°C');
            $chReturnTemp->setIsHistorized(1);
            $chReturnTemp->save();

            // Holiday mode
            $holiday = $this->getCmd(null, 'holiday');
            if (!is_object($holiday)) {
                $holiday = new aristonCmd();
                $holiday->setLogicalId('holiday');
                $holiday->setEqLogic_id($this->getId());
            }
            $holiday->setName(__('Mode vacances', __FILE__));
            $holiday->setType('info');
            $holiday->setSubType('binary');
            $holiday->setIsHistorized(0);
            $holiday->save();

            // Errors count
            $errorsCount = $this->getCmd(null, 'errors_count');
            if (!is_object($errorsCount)) {
                $errorsCount = new aristonCmd();
                $errorsCount->setLogicalId('errors_count');
                $errorsCount->setEqLogic_id($this->getId());
            }
            $errorsCount->setName(__('Nb erreurs', __FILE__));
            $errorsCount->setType('info');
            $errorsCount->setSubType('numeric');
            $errorsCount->setIsHistorized(0);
            $errorsCount->save();

            // Energy: CH gas today
            $chGasToday = $this->getCmd(null, 'ch_gas_today');
            if (!is_object($chGasToday)) {
                $chGasToday = new aristonCmd();
                $chGasToday->setLogicalId('ch_gas_today');
                $chGasToday->setEqLogic_id($this->getId());
            }
            $chGasToday->setName(__('Gaz CH (aujourd\'hui)', __FILE__));
            $chGasToday->setType('info');
            $chGasToday->setSubType('numeric');
            $chGasToday->setUnite('kWh');
            $chGasToday->setIsHistorized(1);
            $chGasToday->save();

            // Energy: CH electricity today
            $chElecToday = $this->getCmd(null, 'ch_elec_today');
            if (!is_object($chElecToday)) {
                $chElecToday = new aristonCmd();
                $chElecToday->setLogicalId('ch_elec_today');
                $chElecToday->setEqLogic_id($this->getId());
            }
            $chElecToday->setName(__('Elec CH (aujourd\'hui)', __FILE__));
            $chElecToday->setType('info');
            $chElecToday->setSubType('numeric');
            $chElecToday->setUnite('kWh');
            $chElecToday->setIsHistorized(1);
            $chElecToday->save();

            // Energy: CH total today
            $chEnergyToday = $this->getCmd(null, 'ch_energy_today');
            if (!is_object($chEnergyToday)) {
                $chEnergyToday = new aristonCmd();
                $chEnergyToday->setLogicalId('ch_energy_today');
                $chEnergyToday->setEqLogic_id($this->getId());
            }
            $chEnergyToday->setName(__('Energie CH totale (aujourd\'hui)', __FILE__));
            $chEnergyToday->setType('info');
            $chEnergyToday->setSubType('numeric');
            $chEnergyToday->setUnite('kWh');
            $chEnergyToday->setIsHistorized(1);
            $chEnergyToday->save();

            // Energy: DHW gas today
            $dhwGasToday = $this->getCmd(null, 'dhw_gas_today');
            if (!is_object($dhwGasToday)) {
                $dhwGasToday = new aristonCmd();
                $dhwGasToday->setLogicalId('dhw_gas_today');
                $dhwGasToday->setEqLogic_id($this->getId());
            }
            $dhwGasToday->setName(__('Gaz ECS (aujourd\'hui)', __FILE__));
            $dhwGasToday->setType('info');
            $dhwGasToday->setSubType('numeric');
            $dhwGasToday->setUnite('kWh');
            $dhwGasToday->setIsHistorized(1);
            $dhwGasToday->save();

            // Energy: DHW electricity today
            $dhwElecToday = $this->getCmd(null, 'dhw_elec_today');
            if (!is_object($dhwElecToday)) {
                $dhwElecToday = new aristonCmd();
                $dhwElecToday->setLogicalId('dhw_elec_today');
                $dhwElecToday->setEqLogic_id($this->getId());
            }
            $dhwElecToday->setName(__('Elec ECS (aujourd\'hui)', __FILE__));
            $dhwElecToday->setType('info');
            $dhwElecToday->setSubType('numeric');
            $dhwElecToday->setUnite('kWh');
            $dhwElecToday->setIsHistorized(1);
            $dhwElecToday->save();

            // Energy: DHW total today
            $dhwEnergyToday = $this->getCmd(null, 'dhw_energy_today');
            if (!is_object($dhwEnergyToday)) {
                $dhwEnergyToday = new aristonCmd();
                $dhwEnergyToday->setLogicalId('dhw_energy_today');
                $dhwEnergyToday->setEqLogic_id($this->getId());
            }
            $dhwEnergyToday->setName(__('Energie ECS totale (aujourd\'hui)', __FILE__));
            $dhwEnergyToday->setType('info');
            $dhwEnergyToday->setSubType('numeric');
            $dhwEnergyToday->setUnite('kWh');
            $dhwEnergyToday->setIsHistorized(1);
            $dhwEnergyToday->save();

        $setCHSetpoint = $this->getCmd(null, 'set_ch_setpoint');
        if (!is_object($setCHSetpoint)) {
            $setCHSetpoint = new aristonCmd();
            $setCHSetpoint->setName(__('Définir consigne CH', __FILE__));
            $setCHSetpoint->setEqLogic_id($this->getId());
            $setCHSetpoint->setLogicalId('set_ch_setpoint');
            $setCHSetpoint->setType('action');
            $setCHSetpoint->setSubType('slider');
            $setCHSetpoint->setConfiguration('minValue', 40);
            $setCHSetpoint->setConfiguration('maxValue', 80);
            $setCHSetpoint->setIsHistorized(0);
            $setCHSetpoint->save();
        }

        $setCHSetpointId = $chSetpoint->getId();
        $setCHSetpoint->setValue($setCHSetpointId);
        $setCHSetpoint->save();

        $setDHWSetpoint = $this->getCmd(null, 'set_dhw_setpoint');
        if (!is_object($setDHWSetpoint)) {
            $setDHWSetpoint = new aristonCmd();
            $setDHWSetpoint->setName(__('Définir consigne ECS', __FILE__));
            $setDHWSetpoint->setEqLogic_id($this->getId());
            $setDHWSetpoint->setLogicalId('set_dhw_setpoint');
            $setDHWSetpoint->setType('action');
            $setDHWSetpoint->setSubType('slider');
            $setDHWSetpoint->setConfiguration('minValue', 40);
            $setDHWSetpoint->setConfiguration('maxValue', 65);
            $setDHWSetpoint->setIsHistorized(0);
            $setDHWSetpoint->save();
        }

        $setDHWSetpointId = $dhwSetpoint->getId();
        $setDHWSetpoint->setValue($setDHWSetpointId);
        $setDHWSetpoint->save();

        $setMode = $this->getCmd(null, 'set_mode');
        if (!is_object($setMode)) {
            $setMode = new aristonCmd();
            $setMode->setName(__('Définir mode', __FILE__));
            $setMode->setEqLogic_id($this->getId());
            $setMode->setLogicalId('set_mode');
            $setMode->setType('action');
            $setMode->setSubType('select');
            $setMode->setConfiguration('listValue', 'summer|Eté;winter|Hiver;heating_only|Chauffage;cooling|Clim;off|Arrêt');
            $setMode->setIsHistorized(0);
            $setMode->save();
        }

        $setModeId = $mode->getId();
        $setMode->setValue($setModeId);
        $setMode->save();
    }
}

class aristonCmd extends cmd {

    public function execute($_options = array()) {
        $eqlogic = $this->getEqLogic();
        $logicalCmd = $this->getLogicalId();

        if (preg_match('/^zone(\d+)_set_setpoint$/', $logicalCmd, $m)) {
            $zoneNum = intval($m[1]);
            $value = floatval($_options['slider']);
            if ($value < 5 || $value > 30) {
                throw new Exception(__('La consigne doit être entre 5 et 30°C', __FILE__));
            }
            $data = array(
                'action' => 'setCHSetpoint',
                'eqId' => $eqlogic->getId(),
                'gw' => $eqlogic->getConfiguration('gw', ''),
                'value' => $value,
                'zone' => $zoneNum,
                'apikey' => jeedom::getApiKey('ariston')
            );
            $value = json_encode($data);
            ariston::socketConnection($value);
            log::add('ariston', 'debug', 'Exécution commande : ' . $this->getName());
            return;
        }

        switch ($logicalCmd) {
            case 'set_ch_setpoint':
                $value = intval($_options['slider']);
                if ($value < 40 || $value > 80) {
                    throw new Exception(__('La consigne CH doit être entre 40 et 80°C', __FILE__));
                }
                $data = array(
                    'action' => 'setCHSetpoint',
                    'eqId' => $eqlogic->getId(),
                    'gw' => $eqlogic->getConfiguration('gw', ''),
                    'value' => floatval($value),
                    'apikey' => jeedom::getApiKey('ariston')
                );
                break;

            case 'set_dhw_setpoint':
                $value = intval($_options['slider']);
                if ($value < 40 || $value > 65) {
                    throw new Exception(__('La consigne ECS doit être entre 40 et 65°C', __FILE__));
                }
                $data = array(
                    'action' => 'setDHWSetpoint',
                    'eqId' => $eqlogic->getId(),
                    'gw' => $eqlogic->getConfiguration('gw', ''),
                    'value' => floatval($value),
                    'apikey' => jeedom::getApiKey('ariston')
                );
                break;

            case 'set_mode':
                $data = array(
                    'action' => 'setMode',
                    'eqId' => $eqlogic->getId(),
                    'gw' => $eqlogic->getConfiguration('gw', ''),
                    'value' => $_options['select'],
                    'apikey' => jeedom::getApiKey('ariston')
                );
                break;

            default:
                throw new Exception(__('Commande non reconnue : ', __FILE__) . $logicalCmd);
        }

        $value = json_encode($data);
        ariston::socketConnection($value);
        log::add('ariston', 'debug', 'Exécution commande : ' . $this->getName());
    }
}
