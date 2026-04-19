<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    if (init('action') == 'getGateways') {
        $email    = config::byKey('email', 'ariston', '');
        $password = config::byKey('password', 'ariston', '');
        if (empty($email) || empty($password)) {
            throw new Exception('Email ou mot de passe non configuré dans le plugin Ariston');
        }

        $api_base = 'https://www.ariston-net.remotethermo.com/api/v2';
        $headers  = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: RestSharp/106.11.7.0',
        ];

        // Login
        $ch = curl_init($api_base . '/accounts/login');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['usr' => $email, 'pwd' => $password]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            throw new Exception('Erreur login Ariston (HTTP ' . $code . ')');
        }
        $login_data = json_decode($resp, true);
        $token      = $login_data['token'] ?? '';
        if (empty($token)) {
            throw new Exception('Token Ariston vide après login');
        }

        // Get plants
        $auth_headers = array_merge($headers, ['ar.authToken: ' . $token]);
        $ch = curl_init($api_base . '/remote/plants');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $auth_headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            throw new Exception('Erreur récupération des gateways (HTTP ' . $code . ')');
        }
        $plants = json_decode($resp, true);
        if (!is_array($plants)) {
            throw new Exception('Réponse inattendue de l\'API Ariston');
        }

        $gateways = [];
        foreach ($plants as $p) {
            $gw   = $p['gw']   ?? '';
            $name = $p['name'] ?? '';
            $sn   = $p['sn']   ?? $gw;
            $sys  = $p['sys']  ?? '';
            if (!empty($gw)) {
                $label = !empty($name) ? $name . ' (' . $sn . ')' : $sn;
                $gateways[] = ['gw' => $gw, 'name' => $label, 'sn' => $sn, 'sys' => $sys];
            }
        }

        ajax::success($gateways);
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
