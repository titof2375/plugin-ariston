import logging
import sys
import os
import time
import traceback
import signal
import json
import argparse
import requests

try:
    from jeedom.jeedom import *
except ImportError as e:
    print('Error: importing module jeedom.jeedom: ' + str(e))
    sys.exit(1)

API_BASE = 'https://www.ariston-net.remotethermo.com/api/v2'
HEADERS_BASE = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'User-Agent': 'RestSharp/106.11.7.0'
}

# PlantMode values
PLANT_MODE_SUMMER       = 0
PLANT_MODE_WINTER       = 1
PLANT_MODE_HEATING_ONLY = 2
PLANT_MODE_COOLING      = 3
PLANT_MODE_COOLING_ONLY = 4
PLANT_MODE_OFF          = 5

# ConsumptionType values (from ariston library)
CONS_CH_TOTAL      = 1
CONS_DHW_TOTAL     = 2
CONS_CH_GAS        = 7
CONS_DHW_GAS       = 10
CONS_CH_ELEC       = 20
CONS_DHW_ELEC      = 21
CONS_INTERVAL_DAY  = 1   # last day

# Menu item IDs
MENU_CH_RETURN_TEMP = 124

_token = None
_email = ''
_password = ''
_features_cache = {}   # gw -> features dict
_last_data_items = {}  # gw -> last raw items list (for prevValue in set calls)

# ------------------------------------------------------------------
# Auth
# ------------------------------------------------------------------

def api_login():
    global _token
    r = requests.post(
        API_BASE + '/accounts/login',
        json={'usr': _email, 'pwd': _password},
        headers=HEADERS_BASE, timeout=30, verify=True
    )
    r.raise_for_status()
    _token = r.json()['token']
    logging.info('Ariston NET login OK')


def _auth_headers():
    if not _token:
        api_login()
    h = dict(HEADERS_BASE)
    h['ar.authToken'] = _token
    return h


def api_get(endpoint, retried=False):
    r = requests.get(API_BASE + endpoint, headers=_auth_headers(), timeout=30, verify=True)
    if r.status_code == 401 and not retried:
        api_login()
        return api_get(endpoint, retried=True)
    r.raise_for_status()
    return r.json()


def api_post(endpoint, payload, retried=False):
    r = requests.post(API_BASE + endpoint, json=payload, headers=_auth_headers(), timeout=30, verify=True)
    if r.status_code == 401 and not retried:
        api_login()
        return api_post(endpoint, payload, retried=True)
    r.raise_for_status()
    try:
        return r.json()
    except Exception:
        return {}

# ------------------------------------------------------------------
# Plants / features
# ------------------------------------------------------------------

def get_plants():
    return api_get('/remote/plants')


def get_features(gw):
    if gw not in _features_cache:
        _features_cache[gw] = api_get('/remote/plants/{}/features'.format(gw))
    return _features_cache[gw]


def _items_list(features):
    device_props = [
        'PlantMode', 'IsFlameOn', 'IsHeatingPumpOn', 'OutsideTemp',
        'HeatingCircuitPressure', 'ChFlowTemp', 'ChFlowSetpointTemp',
        'DhwTemp', 'DhwMode', 'AutomaticThermoregulation', 'Holiday'
    ]
    thermo_props = [
        'ZoneMeasuredTemp', 'ZoneDesiredTemp', 'ZoneComfortTemp',
        'ZoneMode', 'ZoneHeatRequest', 'ZoneEconomyTemp'
    ]
    items = [{'id': p, 'zone': 0} for p in device_props]
    for z in features.get('zones', []):
        items += [{'id': p, 'zone': z['num']} for p in thermo_props]
    return items

# ------------------------------------------------------------------
# Data parsing helpers
# ------------------------------------------------------------------

def _get_val(items, item_id, zone=0, key='value'):
    for it in items:
        if it.get('id') == item_id and it.get('zone') == zone:
            return it.get(key)
    return None


def _parse_data(items, features):
    data = {}
    zones = features.get('zones', [])

    flame = _get_val(items, 'IsFlameOn')
    if flame is not None:
        data['flame'] = bool(flame)

    mode = _get_val(items, 'PlantMode')
    if mode is not None:
        data['mode'] = int(mode)

    pressure = _get_val(items, 'HeatingCircuitPressure')
    if pressure is not None:
        try:
            data['pressure'] = round(float(pressure), 2)
        except (TypeError, ValueError):
            pass

    outside = _get_val(items, 'OutsideTemp')
    if outside is not None:
        try:
            v = float(outside)
            if -50 < v < 80:
                data['outside_temp'] = round(v, 1)
        except (TypeError, ValueError):
            pass

    ch_flow = _get_val(items, 'ChFlowTemp')
    if ch_flow is not None:
        try:
            data['ch_flow_temp'] = round(float(ch_flow), 1)
        except (TypeError, ValueError):
            pass

    ch_sp = _get_val(items, 'ChFlowSetpointTemp')
    if ch_sp is not None:
        try:
            data['ch_setpoint'] = round(float(ch_sp), 1)
        except (TypeError, ValueError):
            pass

    dhw = _get_val(items, 'DhwTemp')
    if dhw is not None:
        try:
            data["dhw_temp"] = round(float(dhw), 1)
            data["dhw_setpoint"] = round(float(dhw), 1)
        except (TypeError, ValueError):
            pass

    holiday = _get_val(items, 'Holiday')
    if holiday is not None:
        data['holiday'] = 1 if holiday else 0

    # First zone
    if zones:
        z_num = zones[0]['num']

        room = _get_val(items, 'ZoneMeasuredTemp', z_num)
        if room is not None:
            try:
                data['room_temp'] = round(float(room), 1)
            except (TypeError, ValueError):
                pass

        room_sp = _get_val(items, 'ZoneComfortTemp', z_num)
        if room_sp is None:
            room_sp = _get_val(items, 'ZoneDesiredTemp', z_num)
        if room_sp is not None:
            try:
                data['room_setpoint'] = round(float(room_sp), 1)
            except (TypeError, ValueError):
                pass

        heat_req = _get_val(items, 'ZoneHeatRequest', z_num)
        if heat_req is not None:
            data['ch_on'] = 1 if heat_req else 0

    ch_on = data.get('ch_on', 0)
    data['dhw_on'] = 1 if (data.get('flame') and not ch_on) else 0

    return data


def _get_cons_val(sequences, cons_type, interval):
    # API returns: k=type, p=period (1=day,2=week,3=month,4=year), v=[array, last=most recent]
    for item in sequences:
        if item.get('k') == cons_type and item.get('p') == interval:
            v = item.get('v')
            if isinstance(v, list) and len(v) > 0:
                try:
                    return round(float(v[-1]), 3)
                except (TypeError, ValueError):
                    pass
    return None

# ------------------------------------------------------------------
# Main data fetch
# ------------------------------------------------------------------

def get_boiler_data(gw):
    data = {}
    try:
        features = get_features(gw)
        payload = {
            'useCache': False,
            'items': _items_list(features),
            'features': features,
            'culture': 'en-US'
        }
        response = api_post('/remote/dataItems/{}/get?umsys=metric'.format(gw), payload)
        raw_items = response.get('items', [])
        _last_data_items[gw] = raw_items
        data = _parse_data(raw_items, features)
        data['online'] = 1
        logging.debug('Boiler data: %s', data)
    except requests.exceptions.HTTPError as e:
        logging.error('HTTP error get_boiler_data: %s', e)
        data['online'] = 0
    except Exception as e:
        logging.error('Error get_boiler_data: %s', e)
        data['online'] = 0

    # CH return temp + signal via menu items (best-effort)
    # Note: /menuItems/ NOT /remote/menuItems/
    try:
        menu_items = api_get('/menuItems/{}?menuItems=124,119,115'.format(gw))
        if isinstance(menu_items, list):
            for item in menu_items:
                if item.get('id') == 124:   # CH_RETURN_TEMP
                    v = item.get('value')
                    if v is not None:
                        try:
                            fv = float(v)
                            if -50 < fv < 200:
                                data['ch_return_temp'] = round(fv, 1)
                        except (TypeError, ValueError):
                            pass
                elif item.get('id') == 119:  # SIGNAL_STRENGTH
                    v = item.get('value')
                    if v is not None:
                        try:
                            data['signal_strength'] = int(float(v))
                        except (TypeError, ValueError):
                            pass
                elif item.get('id') == 115:  # MODULATION (%)
                    v = item.get('value')
                    if v is not None:
                        try:
                            data['modulation'] = round(float(v), 1)
                        except (TypeError, ValueError):
                            pass
    except Exception as e:
        logging.debug('Menu items unavailable: %s', e)

    # Bus errors
    try:
        errors = api_get('/busErrors?gatewayId={}&blockingOnly=False&culture=en-US'.format(gw))
        if isinstance(errors, list) and errors:
            err = errors[0]
            data['error_code'] = '{} - {}'.format(
                err.get('code', ''),
                err.get('fault', err.get('description', ''))
            )
            data['errors_count'] = len(errors)
        else:
            data['error_code'] = ''
            data['errors_count'] = 0
    except Exception as e:
        logging.debug('Bus errors unavailable: %s', e)
        data['error_code'] = ''
        data['errors_count'] = 0

    # Energy / consumption (best-effort)
    try:
        sequences = api_get('/remote/reports/{}/consSequencesApi8?usages=Ch%2CDhw'.format(gw))
        if isinstance(sequences, list):
            v = _get_cons_val(sequences, CONS_CH_GAS, CONS_INTERVAL_DAY)
            if v is not None: data['ch_gas_today'] = v
            v = _get_cons_val(sequences, CONS_CH_ELEC, CONS_INTERVAL_DAY)
            if v is not None: data['ch_elec_today'] = v
            v = _get_cons_val(sequences, CONS_CH_TOTAL, CONS_INTERVAL_DAY)
            if v is not None: data['ch_energy_today'] = v
            v = _get_cons_val(sequences, CONS_DHW_GAS, CONS_INTERVAL_DAY)
            if v is not None: data['dhw_gas_today'] = v
            v = _get_cons_val(sequences, CONS_DHW_ELEC, CONS_INTERVAL_DAY)
            if v is not None: data['dhw_elec_today'] = v
            v = _get_cons_val(sequences, CONS_DHW_TOTAL, CONS_INTERVAL_DAY)
            if v is not None: data['dhw_energy_today'] = v
        logging.debug('Energy data: ch_gas=%s ch_elec=%s dhw_gas=%s dhw_elec=%s',
                      data.get('ch_gas_today'), data.get('ch_elec_today'),
                      data.get('dhw_gas_today'), data.get('dhw_elec_today'))
    except Exception as e:
        logging.debug('Energy data unavailable: %s', e)

    return data

# ------------------------------------------------------------------
# Setters
# ------------------------------------------------------------------

def _set_item(gw, item_id, new_value, zone=0):
    features = get_features(gw)
    cached = _last_data_items.get(gw, [])
    prev_value = _get_val(cached, item_id, zone)
    if prev_value is None:
        prev_value = new_value
    payload = {
        'items': [{'id': item_id, 'prevValue': prev_value, 'value': new_value, 'zone': zone}],
        'features': features
    }
    api_post('/remote/dataItems/{}/set?umsys=metric'.format(gw), payload)


def set_ch_setpoint(gw, value):
    features = get_features(gw)
    zones = features.get('zones', [])
    z_num = zones[0]['num'] if zones else 1
    _set_item(gw, 'ZoneComfortTemp', value, z_num)


def set_dhw_setpoint(gw, value):
    _set_item(gw, 'DhwTemp', value, 0)


def set_mode(gw, mode_str):
    mode_map = {
        'summer':       PLANT_MODE_SUMMER,
        'winter':       PLANT_MODE_WINTER,
        'heating_only': PLANT_MODE_HEATING_ONLY,
        'cooling':      PLANT_MODE_COOLING,
        'cooling_only': PLANT_MODE_COOLING_ONLY,
        'off':          PLANT_MODE_OFF,
        'auto':         PLANT_MODE_WINTER,
    }
    mode_int = mode_map.get(mode_str.lower(), PLANT_MODE_WINTER)
    _set_item(gw, 'PlantMode', mode_int, 0)

# ------------------------------------------------------------------
# Socket handler
# ------------------------------------------------------------------

def read_socket():
    global JEEDOM_SOCKET_MESSAGE
    if not JEEDOM_SOCKET_MESSAGE.empty():
        logging.debug('Message received on socket')
        message = json.loads(JEEDOM_SOCKET_MESSAGE.get())
        if message.get('apikey') != _apikey:
            logging.error('Invalid apikey from socket: %s', message)
            return
        try:
            action = message.get('action', '')
            eqId   = message.get('eqId', '')
            gw     = message.get('gw', '')

            if action == 'getDatas':
                if not gw:
                    plants = get_plants()
                    jeedom_com.send_change_immediate({'FUNC': 'plants', 'data': plants})
                    return
                data = get_boiler_data(gw)
                jeedom_com.send_change_immediate({'FUNC': 'getDatas', 'eqId': eqId, 'data': data})

            elif action == 'setCHSetpoint':
                value = float(message.get('value', 20))
                set_ch_setpoint(gw, value)
                time.sleep(3)
                data = get_boiler_data(gw)
                jeedom_com.send_change_immediate({'FUNC': 'getDatas', 'eqId': eqId, 'data': data})

            elif action == 'setDHWSetpoint':
                value = float(message.get('value', 50))
                set_dhw_setpoint(gw, value)
                time.sleep(3)
                data = get_boiler_data(gw)
                jeedom_com.send_change_immediate({'FUNC': 'getDatas', 'eqId': eqId, 'data': data})

            elif action == 'setMode':
                mode_str = message.get('value', 'winter')
                set_mode(gw, mode_str)
                time.sleep(3)
                data = get_boiler_data(gw)
                jeedom_com.send_change_immediate({'FUNC': 'getDatas', 'eqId': eqId, 'data': data})

        except Exception as e:
            logging.error('Error processing socket message: %s\n%s', e, traceback.format_exc())


def listen():
    jeedom_socket.open()
    try:
        while True:
            time.sleep(0.5)
            read_socket()
    except KeyboardInterrupt:
        shutdown()


def handler(signum=None, frame=None):
    logging.debug('Signal %i caught, exiting...', int(signum))
    shutdown()


def shutdown():
    logging.debug('Shutdown')
    try:
        os.remove(_pidfile)
    except Exception:
        pass
    try:
        jeedom_socket.close()
    except Exception:
        pass
    logging.debug('Exit 0')
    sys.stdout.flush()
    os._exit(0)

# ------------------------------------------------------------------
# Argument parsing & startup
# ------------------------------------------------------------------

_log_level   = 'error'
_socket_port = 57131
_socket_host = 'localhost'
_pidfile     = '/tmp/aristond.pid'
_apikey      = ''
_callback    = ''

parser = argparse.ArgumentParser(description='Daemon for Jeedom ariston plugin')
parser.add_argument('--loglevel',   help='Log Level',        type=str)
parser.add_argument('--callback',   help='Callback URL',     type=str)
parser.add_argument('--apikey',     help='Apikey',           type=str)
parser.add_argument('--pid',        help='Pid file',         type=str)
parser.add_argument('--socketport', help='Socket port',      type=int)
parser.add_argument('--sockethost', help='Socket host',      type=str)
parser.add_argument('--email',      help='Ariston email',    type=str)
parser.add_argument('--password',   help='Ariston password', type=str)
args = parser.parse_args()

if args.loglevel:   _log_level   = args.loglevel
if args.callback:   _callback    = args.callback
if args.apikey:     _apikey      = args.apikey
if args.pid:        _pidfile     = args.pid
if args.socketport: _socket_port = int(args.socketport)
if args.sockethost: _socket_host = args.sockethost
if args.email:      _email       = args.email
if args.password:   _password    = args.password

jeedom_utils.set_log_level(_log_level)

logging.info('Start aristond')
logging.info('Log level  : %s', _log_level)
logging.info('Socket port: %s', _socket_port)
logging.info('PID file   : %s', _pidfile)
logging.info('Callback   : %s', _callback)

signal.signal(signal.SIGINT,  handler)
signal.signal(signal.SIGTERM, handler)

try:
    jeedom_utils.write_pid(str(_pidfile))
    jeedom_com    = jeedom_com(apikey=_apikey, url=_callback)
    if not jeedom_com.test():
        logging.error('Network communication issues. Please fix your Jeedom network configuration.')
        shutdown()
    jeedom_socket = jeedom_socket(port=_socket_port, address=_socket_host)
    listen()
except Exception as e:
    logging.error('Fatal error: %s\n%s', e, traceback.format_exc())
    shutdown()
