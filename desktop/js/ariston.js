/* Plugin Ariston - JS Desktop */

$(function() {
    $('#table_cmd').sortable({
        axis: 'y',
        cursor: 'move',
        items: '.cmd',
        placeholder: 'ui-state-highlight',
        tolerance: 'intersect',
        forcePlaceholderSize: true
    });

    // Detect gateways button
    $(document).off('click', '#bt_detectGateways').on('click', '#bt_detectGateways', function() {
        var btn = $(this);
        btn.find('i').addClass('fa-spin');
        btn.prop('disabled', true);

        $.ajax({
            type: 'POST',
            url: 'plugins/ariston/core/ajax/ariston.ajax.php',
            data: {action: 'getGateways'},
            dataType: 'json',
            error: function(request, status, error) {
                handleAjaxError(request, status, error);
                btn.find('i').removeClass('fa-spin');
                btn.prop('disabled', false);
            },
            success: function(data) {
                btn.find('i').removeClass('fa-spin');
                btn.prop('disabled', false);
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
                var currentVal = $('#in_ariston_gw_hidden').val();
                var select = $('#sel_ariston_gw');
                select.empty();
                select.append('<option value="">-- Choisir une passerelle --</option>');
                if (data.result.length === 0) {
                    select.append('<option value="" disabled>Aucune passerelle trouvee</option>');
                } else {
                    $.each(data.result, function(i, gw) {
                        select.append('<option value="' + gw.gw + '">' + gw.name + '</option>');
                    });
                    // Re-select saved value, or auto-select if only one
                    if (currentVal && select.find('option[value="' + currentVal + '"]').length > 0) {
                        select.val(currentVal);
                        // Sync the name too
                        var label = select.find('option:selected').text();
                        $('#in_ariston_gw_name').val(label);
                        $('#lbl_ariston_gw').text('Passerelle : ' + label).css('color', 'green');
                    } else if (data.result.length === 1) {
                        select.val(data.result[0].gw);
                        $('#in_ariston_gw_hidden').val(data.result[0].gw);
                        $('#in_ariston_gw_name').val(data.result[0].name);
                        $('#lbl_ariston_gw').text('Passerelle : ' + data.result[0].name).css('color', 'green');
                    }
                }
                $('#div_alert').showAlert({message: data.result.length + ' passerelle(s) trouvee(s). Selectionnez puis Sauvegarder.', level: 'success'});
            }
        });
    });

    // When select changes, sync ID and name to hidden inputs
    $(document).off('change', '#sel_ariston_gw').on('change', '#sel_ariston_gw', function() {
        var val = $(this).val();
        var label = $(this).find('option:selected').text();
        $('#in_ariston_gw_hidden').val(val);
        $('#in_ariston_gw_name').val(val ? label : '');
        if (val) {
            $('#lbl_ariston_gw').text('Passerelle : ' + label).css('color', 'green');
        } else {
            $('#lbl_ariston_gw').text('');
        }
    });
});

// Called by Jeedom after loading eqLogic data into the form
function printEqLogic(data) {
    var gw   = (data && data.configuration && data.configuration.gw)      ? data.configuration.gw      : '';
    var name = (data && data.configuration && data.configuration.gw_name) ? data.configuration.gw_name : '';
    var select = $('#sel_ariston_gw');
    if (gw) {
        var label = name ? name : gw;
        select.empty().append('<option value="' + gw + '">' + label + '</option>');
        select.val(gw);
        $('#lbl_ariston_gw').text('Passerelle actuelle : ' + label).css('color', 'blue');
    } else {
        select.empty().append('<option value="">-- Cliquez sur Detecter --</option>');
        $('#lbl_ariston_gw').text('');
    }
}

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs">';
    tr += '<span class="cmdAttr" data-l1key="id"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<div class="input-group">';
    tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">';
    tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icone}}"><i class="fas fa-icons"></i></a></span>';
    tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>';
    tr += '</div>';
    tr += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none;margin-top:5px;" title="{{Commande info liee}}">';
    tr += '<option value="">{{Aucune}}</option>';
    tr += '</select>';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
    tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> ';
    tr += '<div style="margin-top:7px;">';
    tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
    tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
    tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="Unite" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
    tr += '</div>';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
    tr += '</td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i>';
    tr += '</td>';
    tr += '</tr>';
    $('#table_cmd tbody').append(tr);
    var tr = $('#table_cmd tbody tr').last();
    jeedom.eqLogic.buildSelectCmd({
        id: $('.eqLogicAttr[data-l1key=id]').value(),
        filter: {type: 'info'},
        error: function(error) {
            $('#div_alert').showAlert({message: error.message, level: 'danger'});
        },
        success: function(result) {
            tr.find('.cmdAttr[data-l1key=value]').append(result);
            tr.setValues(_cmd, '.cmdAttr');
            jeedom.cmd.changeType(tr, init(_cmd.subType));
        }
    });
}
