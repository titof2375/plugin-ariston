<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
    <fieldset>
        <div class="form-group">
            <label class="col-md-4 control-label">{{SocketPort}}</label>
            <div class="col-md-4">
                <input class="configKey form-control" data-l1key="socketport" placeholder="57131"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Intervalle de rafraîchissement (minutes)}}</label>
            <div class="col-md-4">
                <select class="configKey form-control" data-l1key="cronchoice">
                    <option value="1">{{1 minute}}</option>
                    <option value="5">{{5 minutes}}</option>
                    <option value="10">{{10 minutes}}</option>
                    <option value="15">{{15 minutes}}</option>
                    <option value="30">{{30 minutes}}</option>
                    <option value="60">{{1 heure}}</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Email Ariston NET}}</label>
            <div class="col-md-4">
                <input class="configKey form-control" data-l1key="email" placeholder="email@exemple.com" type="email"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Mot de passe}}</label>
            <div class="col-md-4">
                <input class="configKey form-control" data-l1key="password" placeholder="mot de passe" type="password"/>
            </div>
        </div>
    </fieldset>
</form>
