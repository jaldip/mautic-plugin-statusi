/**
 * Statusi integrations (Resources/views/Integrations).
 *
 *  - Activate / Deactivate buttons: AJAX POST (Mautic adds and checks the CSRF token), then the list reloads.
 *  - form[data-statusi-form] (Ongage, Click Go, ChatGPT Ads, accounts, field mapping): AJAX POST, errors shown in
 *    the form, success goes to the page the server names (usually the list).
 *  - Ongage field mapping (#statusiOngageMapping): ticking a list loads its Ongage fields and adds its mapping table,
 *    preselected like the default mapping; unticking removes it.
 */
(function () {
'use strict';

Mautic.statusiIntegrationsOnLoad = function (container) {
    var $root = findIn(container, '#statusiIntegrations');
    if (!$root.length) {
        return;
    }

    var requestFailed = $root.data('request-failed');
    var refreshUrl    = $root.data('refresh-url');

    $root.off('.statusi').on('click.statusi', '[data-statusi-toggle]', function () {
        var $button = mQuery(this);
        $button.prop('disabled', true);
        mQuery.ajax({
            url: $button.data('url'),
            type: 'POST',
            data: {active: $button.data('active')},
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success) {
                Mautic.loadContent(refreshUrl);
            } else {
                window.alert((response && response.error) || requestFailed);
                $button.prop('disabled', false);
            }
        }).fail(function (xhr) {
            window.alert((xhr.responseJSON && xhr.responseJSON.error) || requestFailed);
            $button.prop('disabled', false);
        });
    });
};

Mautic.statusiIntegrationFormOnLoad = function (container) {
    findIn(container, 'form[data-statusi-form]').off('.statusi').on('submit.statusi', function (event) {
        event.preventDefault();
        var $form   = mQuery(this);
        var $error  = $form.find('[data-statusi-form-error]').last();
        var $submit = $form.find('[type="submit"]');

        $error.addClass('hide').text('');
        $submit.prop('disabled', true);

        mQuery.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success && response.redirect) {
                Mautic.loadContent(response.redirect);
                return;
            }
            showFormError($error, response);
            $submit.prop('disabled', false);
        }).fail(function (xhr) {
            showFormError($error, xhr.responseJSON);
            $submit.prop('disabled', false);
        });
    });

    var $mapping = findIn(container, '#statusiOngageMapping');
    if ($mapping.length) {
        initOngageMapping($mapping);
    }
};

function initOngageMapping($form) {
    var fields   = $form.data('fields') || [];
    var defaults = $form.data('defaults') || {};
    var labels   = {none: $form.data('none-label'), statusi: $form.data('statusi-label'), ongage: $form.data('ongage-label'), loading: $form.data('loading')};

    $form.off('change.statusiList').on('change.statusiList', '[data-statusi-list]', function () {
        var listId = this.value;
        var $table = $form.find('[data-list-table="' + listId + '"]');

        if (!this.checked) {
            $table.empty();
            return;
        }

        $table.html(mQuery('<p class="text-secondary small"></p>').text(labels.loading));
        mQuery.ajax({
            url: $form.data('fields-url'),
            type: 'GET',
            data: {list_id: listId},
            dataType: 'json'
        }).done(function (response) {
            $table.empty().append(buildTable(listId, fields, (response && response.titles) || [], currentDefaults($form, defaults), labels));
        }).fail(function (xhr) {
            $table.html(mQuery('<p class="text-danger small"></p>').text((xhr.responseJSON && xhr.responseJSON.error) || $form.closest('[data-request-failed]').data('request-failed')));
        });
    });
}

/** The default mapping as currently chosen on the page (falls back to the loaded values). */
function currentDefaults($form, defaults) {
    var values = mQuery.extend({}, defaults);
    $form.find('select[name^="mapping[default]"]').each(function () {
        var key = this.name.replace(/^mapping\[default\]\[/, '').replace(/\]$/, '');
        values[key] = this.value;
    });

    return values;
}

/** Same markup as the mappingTable macro in ongage_mapping.html.twig. */
function buildTable(listId, fields, titles, defaults, labels) {
    var lower = titles.map(function (title) { return String(title).toLowerCase(); });
    var $table = mQuery('<table class="table table-condensed statusi-mapping-table"></table>');
    $table.append(mQuery('<thead></thead>').append(mQuery('<tr></tr>')
        .append(mQuery('<th></th>').text(labels.statusi))
        .append(mQuery('<th></th>').text(labels.ongage))));

    var $body = mQuery('<tbody></tbody>');
    fields.forEach(function (field) {
        // Preselect: the default mapping's field if this list has it, else the field with the same name.
        var wanted   = String(defaults[field.key] || 'None').toLowerCase();
        var index    = 'none' === wanted ? -1 : lower.indexOf(wanted);
        if (-1 === index) {
            index = lower.indexOf(String(field.label).toLowerCase());
        }

        var $select = mQuery('<select class="form-control input-sm"></select>').attr('name', 'mapping[' + listId + '][' + field.key + ']');
        $select.append(mQuery('<option value="None"></option>').text(labels.none));
        titles.forEach(function (title, i) {
            $select.append(mQuery('<option></option>').attr('value', title).text(title).prop('selected', i === index));
        });

        $body.append(mQuery('<tr></tr>').append(mQuery('<td></td>').text(field.label)).append(mQuery('<td></td>').append($select)));
    });

    return $table.append($body);
}

function findIn(container, selector) {
    var $found = mQuery(container).find(selector);

    return $found.length ? $found : mQuery(selector);
}

function showFormError($error, response) {
    var message = (response && response.error) || $error.closest('[data-request-failed]').data('request-failed');
    $error.text(message).removeClass('hide');
}
})();
