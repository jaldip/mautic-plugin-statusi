/**
 * Read-only Statusi report table (Statusi > report page).
 *
 * Ports the table part of the Statusi UI report page (statusi_ui/src/pages/Reports):
 *   - formatCellValue()       <- common/table/formatCellValue.ts
 *   - buildColumns()          <- common/table/GenerateReportColumn.tsx (getReportTableColumns)
 *   - breadcrumbs / drill     <- Reports/Breadcrumbs.tsx, Reports/ReportTable.tsx (handleExpandedRowChange)
 *   - infinite scroll         <- Reports/ReportTable.tsx (20 rows per page)
 * Without color scales, thresholds, sorting, graphs, export or filter editing. The request itself
 * (filters, rows, values, ...) is built server side; this script only sends the drill-down path and page.
 */
(function () {
'use strict';

Mautic.statusiReportOnLoad = function (container) {
    const root = mQuery(container).find('#statusiReport').get(0) || document.getElementById('statusiReport');
    if (!root) {
        return;
    }

    if (Mautic.statusiReport) {
        Mautic.statusiReport.destroy();
    }
    Mautic.statusiReport = new StatusiReport(root);
};

Mautic.statusiReportOnUnload = function () {
    if (Mautic.statusiReport) {
        Mautic.statusiReport.destroy();
        Mautic.statusiReport = null;
    }
};

function StatusiReport(root) {
    let configuration = {};
    try {
        configuration = JSON.parse(root.dataset.configuration || '{}') || {};
    } catch (e) {
        configuration = {};
    }

    this.root          = root;
    this.url           = root.dataset.url;
    this.slug          = root.dataset.slug || '';
    this.accountId     = root.dataset.accountId || '';
    this.messages      = {loading: root.dataset.loading, empty: root.dataset.empty, error: root.dataset.error};
    this.rows          = configuration.rows || [];
    this.values        = configuration.values || [];
    this.columnData    = configuration.columns || [];
    this.systemRows    = configuration.system_rows || [];
    this.configuration = configuration;

    this.breadcrumbsEl = root.querySelector('.statusi-report-breadcrumbs');
    this.scrollEl      = root.querySelector('.statusi-report-scroll');
    this.tableEl       = root.querySelector('.statusi-report-table');
    this.statusEl      = root.querySelector('.statusi-report-status');

    this.operator        = {};
    this.operatorIdValue = {};
    this.reportData      = [];
    this.parentData      = null;
    this.pageIndex       = 1;
    this.hasMoreData     = false;
    this.loading         = false;
    this.requestId       = 0;

    this.onScroll = this.handleScroll.bind(this);
    this.scrollEl.addEventListener('scroll', this.onScroll);

    this.fetch(1, false);
}

StatusiReport.prototype.destroy = function () {
    this.requestId++;
    this.scrollEl.removeEventListener('scroll', this.onScroll);
};

StatusiReport.prototype.rowConfigFields = function () {
    return new Set(this.rows.map((r) => (r.hasRowId ? r.rowField : r.field)));
};

StatusiReport.prototype.operatorLength = function () {
    const fields = this.rowConfigFields();

    return Object.keys(this.operator).filter((key) => fields.has(key)).length;
};

/* ------------------------------------------------------------------ data */

StatusiReport.prototype.fetch = function (pageIndex, append) {
    const requestId = ++this.requestId;
    const params    = new URLSearchParams({page: String(pageIndex), operator: JSON.stringify(this.operator)});

    this.loading = true;
    this.setStatus(this.messages.loading, false);

    fetch(this.url + '?' + params.toString(), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then((response) => response.json().catch(() => ({})).then((body) => ({ok: response.ok, body})))
        .then(({ok, body}) => {
            if (requestId !== this.requestId) {
                return;
            }
            if (!ok) {
                throw new Error(body.error || this.messages.error);
            }

            const newData = Array.isArray(body.data) ? body.data : [];
            this.reportData  = append ? this.reportData.concat(newData) : newData;
            if (!append) {
                this.parentData = body.parentData || null;
            }
            this.pageIndex   = body.pageIndex || pageIndex;
            this.hasMoreData = newData.length === (body.pageSize || 20);
            this.loading     = false;

            this.render();
            this.setStatus(this.reportData.length ? '' : this.messages.empty, false);
            this.fillViewport();
        })
        .catch((error) => {
            if (requestId !== this.requestId) {
                return;
            }
            this.loading = false;
            if (!append) {
                this.reportData = [];
                this.render();
            }
            this.setStatus(error.message || this.messages.error, true);
        });
};

StatusiReport.prototype.handleScroll = function () {
    const el         = this.scrollEl;
    const nearBottom = Math.abs(el.scrollTop + el.clientHeight - el.scrollHeight) <= 2;

    if (nearBottom && this.hasMoreData && !this.loading) {
        this.fetch(this.pageIndex + 1, true);
    }
};

// When the rows don't fill the scroll area there is nothing to scroll: load the next page directly
StatusiReport.prototype.fillViewport = function () {
    if (this.hasMoreData && !this.loading && this.scrollEl.scrollHeight <= this.scrollEl.clientHeight) {
        this.fetch(this.pageIndex + 1, true);
    }
};

StatusiReport.prototype.setStatus = function (message, isError) {
    this.statusEl.textContent = message || '';
    this.statusEl.classList.toggle('text-danger', !!isError);
};

/* ------------------------------------------------------------ drill-down */

// Port of handleExpandedRowChange() (ReportTable.tsx)
StatusiReport.prototype.drill = function (row) {
    const currentLevel = this.operatorLength();
    const rowConfig    = this.rows[currentLevel];
    const hasRowId     = rowConfig && rowConfig.hasRowId;
    const currentField = rowConfig ? (hasRowId ? rowConfig.rowField : rowConfig.field) : null;

    if (!currentField) {
        return;
    }

    let value = hasRowId ? (row.row_id ?? null) : row.row_label;
    if ((currentField.includes('date') || currentField.includes('activity_date')) && moment(value).isValid()) {
        value = moment(value).format('YYYY-MM-DD');
    }
    if (!value) {
        return;
    }

    const newOperator = Object.assign({}, this.operator, {[currentField]: value});
    this.systemRows.forEach((sysRow) => {
        const fieldKey = sysRow.field;
        if (fieldKey && Object.prototype.hasOwnProperty.call(row, fieldKey)) {
            newOperator[fieldKey] = row[fieldKey];
        }
    });

    this.operatorIdValue = Object.assign({}, this.operatorIdValue, {[currentField]: row.row_label});
    this.setOperator(newOperator);
};

StatusiReport.prototype.setOperator = function (operator) {
    this.operator    = operator;
    this.reportData  = [];
    this.parentData  = null;
    this.hasMoreData = false;
    this.scrollEl.scrollTop = 0;
    this.render();
    this.fetch(1, false);
};

// Port of the crumbs part of Breadcrumbs.tsx (no edit mode and no sibling dropdown: read-only)
StatusiReport.prototype.renderBreadcrumbs = function () {
    const el = this.breadcrumbsEl;
    el.innerHTML = '';

    if (this.rows.length > 0 && this.rows[0].lable) {
        el.appendChild(this.crumb(this.rows[0].lable, () => {
            this.operatorIdValue = {};
            this.setOperator({});
        }));
    }

    const operatorKeysInOrder = this.rows
        .map((r) => (r ? (r.hasRowId ? r.rowField : r.field) : null))
        .filter((f) => f && this.operator[f] !== undefined);

    operatorKeysInOrder.forEach((currentField, level) => {
        const currentValue = this.operatorIdValue[currentField];
        const rowConfig    = this.rows.find((r) => (r.hasRowId ? r.rowField : r.field) === currentField);
        if (!rowConfig || !currentValue) {
            return;
        }

        el.appendChild(this.crumb(this.formatCrumbValue(currentValue, currentField, rowConfig), () => {
            const newOperator        = {};
            const newOperatorIdValue = {};
            for (let i = 0; i <= level; i++) {
                const fieldToKeep = operatorKeysInOrder[i];
                newOperator[fieldToKeep]        = this.operator[fieldToKeep];
                newOperatorIdValue[fieldToKeep] = this.operatorIdValue[fieldToKeep];
            }
            Object.keys(this.operator).forEach((opKey) => {
                if (!operatorKeysInOrder.includes(opKey)) {
                    newOperator[opKey] = this.operator[opKey];
                }
            });
            this.operatorIdValue = newOperatorIdValue;
            this.setOperator(newOperator);
        }));
    });
};

StatusiReport.prototype.crumb = function (label, onClick) {
    const li   = document.createElement('li');
    const link = document.createElement('a');
    link.href        = 'javascript:void(0)';
    link.textContent = String(label);
    link.title       = String(label);
    link.addEventListener('click', onClick);
    li.appendChild(link);

    return li;
};

StatusiReport.prototype.formatCrumbValue = function (valueToFormat, currentField, rowConfig) {
    const format  = rowConfig.format || {};
    const yearKey = Object.keys(this.operator).find((k) => k.toLowerCase().includes('year'));
    const year    = yearKey ? this.operator[yearKey] : null;

    if (year) {
        if (format.weekToFormat) {
            return this.formatCellValue(`${valueToFormat}-${year}`, currentField, 'rows');
        }
        if (format.yearMonth) {
            return this.formatCellValue(`${year}-${valueToFormat}`, currentField, 'rows');
        }
    }

    return this.formatCellValue(valueToFormat, currentField, 'rows');
};

/* --------------------------------------------------------------- render */

StatusiReport.prototype.render = function () {
    this.renderBreadcrumbs();

    const columns = this.buildColumns();
    const table   = this.tableEl;
    table.innerHTML = '';
    if (!columns.length) {
        return;
    }

    const thead = document.createElement('thead');
    this.buildHeaderRows(columns).forEach((headerRow) => {
        const tr = document.createElement('tr');
        headerRow.forEach((header) => {
            const th = document.createElement('th');
            th.colSpan = header.colSpan;
            if (header.column) {
                const span = document.createElement('span');
                span.className   = 'text-nowrap';
                span.textContent = header.column.header;
                th.appendChild(span);
                if (header.column.info) {
                    const icon = document.createElement('i');
                    icon.className = 'ri-information-line statusi-report-info';
                    icon.title     = header.column.infoMessage || '';
                    th.appendChild(icon);
                }
            }
            tr.appendChild(th);
        });
        thead.appendChild(tr);
    });
    table.appendChild(thead);

    const leaves = this.leafColumns(columns);
    const tbody  = document.createElement('tbody');
    this.reportData.forEach((row) => {
        const tr = document.createElement('tr');
        leaves.forEach((column) => {
            const td = document.createElement('td');
            if (column.render) {
                column.render(td, row);
            } else {
                const value = column.cell ? column.cell(row) : '';
                const text  = value === null || value === undefined ? '' : String(value);
                td.textContent = text;
                if (text.length > 30) {
                    td.title = text;
                }
            }
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
};

StatusiReport.prototype.leafColumns = function (columns) {
    return columns.reduce((leaves, column) => leaves.concat(
        column.columns && column.columns.length ? this.leafColumns(column.columns) : [column]
    ), []);
};

StatusiReport.prototype.depth = function (column) {
    return column.columns && column.columns.length ? 1 + Math.max(...column.columns.map((c) => this.depth(c))) : 1;
};

// Header rows like @tanstack/react-table header groups: groups on top, leaf headers in the last
// row, empty placeholder cells above shallower columns.
StatusiReport.prototype.buildHeaderRows = function (columns) {
    const maxDepth   = Math.max(...columns.map((c) => this.depth(c)));
    const headerRows = Array.from({length: maxDepth}, () => []);

    const place = (column, level) => {
        const colSpan = this.leafColumns([column]).length;
        if (column.columns && column.columns.length) {
            headerRows[level].push({column, colSpan});
            column.columns.forEach((child) => place(child, level + 1));

            return;
        }
        for (let i = level; i < maxDepth - 1; i++) {
            headerRows[i].push({column: null, colSpan});
        }
        headerRows[maxDepth - 1].push({column, colSpan});
    };
    columns.forEach((column) => place(column, 0));

    return headerRows;
};

/* -------------------------------------------- columns (GenerateReportColumn.tsx) */

StatusiReport.prototype.buildColumns = function () {
    const reportData = this.reportData;
    const rows       = this.rows;
    const values     = this.values;
    const columnData = this.columnData;
    const systemRows = this.systemRows;
    const slug       = this.slug;
    const operator   = this.operator;
    const parentData = this.parentData;
    const fmt        = (value, field, type) => this.formatCellValue(value, field, type);

    const shouldShowTotalBracket = (itemField) => {
        if (slug === 'audience-health-report' && String(this.accountId) === '100') {
            return itemField === 'lists_snapshot.sum_of_unsubscribe';
        }

        return true;
    };

    const getSystemRowsYearField = systemRows.find((s) => s.field && s.field.toLowerCase().includes('year'));
    const accessKey              = getSystemRowsYearField ? getSystemRowsYearField.field
        : (reportData.length > 0 ? Object.keys(reportData[0]).find((k) => k.toLowerCase().includes('year')) : null);
    const operatorLength         = this.operatorLength();

    const calculateHeaderAggregate = (item, getValueFn, context) => {
        if (!reportData.length || !['sum', 'average', 'groupConcat'].includes(item.action)) {
            return null;
        }

        let computedValue = 0;
        if (item.action === 'groupConcat' && item.expression) {
            const {orderBy, orderField} = item.expression;
            const isDesc                = orderBy === 'DESC';
            let bestRow                 = reportData[0];
            let bestOrderVal            = getValueFn(bestRow, orderField);
            for (let i = 1; i < reportData.length; i++) {
                const orderVal = getValueFn(reportData[i], orderField);
                if (isDesc ? orderVal > bestOrderVal : orderVal < bestOrderVal) {
                    bestRow      = reportData[i];
                    bestOrderVal = orderVal;
                }
            }
            computedValue = getValueFn(bestRow, item.field);
        } else if (item.action === 'average' && typeof item.field === 'string' && item.field.includes('/')) {
            const [numField, denField] = item.field.split('/');
            const sumNumerator         = reportData.reduce((acc, row) => acc + (Number(getValueFn(row, numField)) || 0), 0);
            const sumDenominator       = reportData.reduce((acc, row) => acc + (Number(getValueFn(row, denField)) || 0), 0);
            computedValue              = sumDenominator ? sumNumerator / sumDenominator : 0;
        } else {
            const total   = reportData.reduce((acc, row) => acc + (Number(getValueFn(row, item.field)) || 0), 0);
            computedValue = item.action === 'average' ? total / reportData.length : total;
        }

        const formatted = fmt(computedValue, item.field, context);
        if (!shouldShowTotalBracket(item.field)) {
            return null;
        }

        return `${item.lable} [${formatted}]`;
    };

    const buildHeaderColumns = (inputData, accessData, accessKeys, maxLevel, currentLevel = 1) => {
        if (currentLevel > maxLevel) {
            return [];
        }
        const result = [];

        for (const key in inputData) {
            const columnFormatConfig = columnData.find((item) => item.format);
            const formattedHeader    = columnFormatConfig ? fmt(key, columnFormatConfig.field, 'columnHeader') : key;
            const newObject          = {header: formattedHeader, columns: []};
            const newAccessKey       = [...accessKeys, key];
            const value              = inputData[key];

            if (typeof value === 'object' && value !== null) {
                newObject.columns = buildHeaderColumns(value, accessData, newAccessKey, maxLevel, currentLevel + 1);
            }

            if (maxLevel === currentLevel) {
                newObject.columns = accessData.map((item) => {
                    let finalHeader = item.lable;

                    if (operatorLength > 0) {
                        const parentValue = nestedValues(newAccessKey, item.field, parentData && parentData[0]);
                        if (shouldShowTotalBracket(item.field)) {
                            finalHeader = `${item.lable} [${fmt(parentValue, item.field, 'column')}]`;
                        }
                    } else {
                        const nestedAccessor = (row, targetField) => {
                            let val = nestedValues(newAccessKey, targetField, row);
                            if (val === null && targetField === key) {
                                val = nestedValues(newAccessKey.slice(0, -1), targetField, row);
                            }

                            return val;
                        };
                        const computedHeader = calculateHeaderAggregate(item, nestedAccessor, 'column');
                        if (computedHeader) {
                            finalHeader = computedHeader;
                        }
                    }

                    return {
                        header:      finalHeader,
                        info:        item.info,
                        infoMessage: item.info_message,
                        cell:        (row) => fmt(nestedValues(newAccessKey, item.field, row), item.field, 'column'),
                    };
                });
            }

            result.push(newObject);
        }

        return result;
    };

    if (!reportData.length) {
        return [];
    }

    const currentRowField = (rows[operatorLength || 0] && rows[operatorLength || 0].field) || '';
    if (/week|year|month/i.test(currentRowField)) {
        reportData.sort((a, b) => {
            const yearA = accessKey ? a[accessKey] : 0;
            const yearB = accessKey ? b[accessKey] : 0;
            if (yearB !== yearA) {
                return yearB - yearA;
            }

            return b.row_label - a.row_label;
        });
    }

    const rowLabelField = (rows[operatorLength || 0] && rows[operatorLength || 0].lable) || 'Row Label';
    const rowLabel      = slug === 'global-retention' ? 'Month' : (slug === 'global-acquisition' ? 'Duration' : rowLabelField);
    const canExpand     = (rows.length - 1) > operatorLength;

    const baseColumns = [{
        header: rowLabel,
        render: (td, row) => {
            const value    = row.row_label;
            const rowField = (rows[operatorLength] && rows[operatorLength].field) || 'row_label';
            const isMonthBasedSlug = [
                'sends-and-clicks-by-isp',
                'nl-npe-by-day-by-isp',
                'clicks-by-nl-by-day-compare',
                'npm-by-day-by-nl',
                'nl-npe-by-day-isp',
            ].includes(slug);
            const isValidYear = (v) => String(v).length === 4 && !isNaN(Number(v));
            const parentField = rows[operatorLength - 1] && rows[operatorLength - 1].field;
            const parentValue = parentField ? operator[parentField] : null;
            const yearFromData = accessKey ? row[accessKey] : null;

            let finalValue = value;
            if (rowField.toLowerCase().includes('week') && String(value).length !== 4) {
                const year = yearFromData ? yearFromData : (isValidYear(parentValue) ? parentValue : Object.values(operator).find(isValidYear));
                if (year) {
                    finalValue = `${value}-${year}`;
                }
            } else if (isMonthBasedSlug && rowField.toLowerCase().includes('month') && String(value).length !== 4) {
                const year = yearFromData ? yearFromData : (isValidYear(parentValue) ? parentValue : Object.values(operator).find(isValidYear));
                if (year) {
                    finalValue = `${year}-${value}`;
                }
            } else if (isMonthBasedSlug && rowField.toLowerCase().includes('day')) {
                const getVal = (key) => {
                    const match = rows.find((r) => r.field && r.field.toLowerCase().includes(key));

                    return match ? match.field : undefined;
                };
                const year  = operator[getVal('year') || ''] || '';
                const month = operator[getVal('month') || ''] || '';
                const day   = String(value).padStart(2, '0');
                if (year && month) {
                    finalValue = `${year}-${String(month).padStart(2, '0')}-${day}`;
                }
            }

            const formatted = fmt(finalValue, rowField, 'rows');
            const text      = formatted === null || formatted === undefined ? '' : String(formatted);
            const label     = document.createElement(canExpand ? 'a' : 'span');
            label.className   = 'statusi-report-row-label';
            label.textContent = text;
            label.title       = text;
            if (canExpand) {
                label.href = 'javascript:void(0)';
                label.addEventListener('click', () => this.drill(row));

                const icon = document.createElement('i');
                icon.className = 'ri-arrow-right-s-line';
                label.appendChild(icon);
            }
            td.appendChild(label);
        },
    }];

    if (columnData.length > 0 && values.length > 0) {
        const inputData = JSON.parse(JSON.stringify(reportData[0]));
        delete inputData.row_label;
        delete inputData.row_id;
        systemRows.forEach((s) => {
            if (s.field && Object.prototype.hasOwnProperty.call(inputData, s.field)) {
                delete inputData[s.field];
            }
        });

        const excludeFieldsHeader = [];
        const filterValues        = [];
        values.forEach((item) => {
            if (item.mergeColumn !== 'exclude') {
                filterValues.push(item);

                return;
            }

            delete inputData[item.field];
            let finalHeader = item.lable;
            if (operatorLength > 0) {
                const parentValue = parentData && parentData[0] ? parentData[0][item.field] : null;
                if (shouldShowTotalBracket(item.field)) {
                    finalHeader = `${item.lable} [${fmt(parentValue, item.field, 'excluded')}]`;
                }
            } else {
                const computedHeader = calculateHeaderAggregate(item, (row, targetField) => row[targetField], 'excluded');
                if (computedHeader) {
                    finalHeader = computedHeader;
                }
            }

            excludeFieldsHeader.push({
                header:      finalHeader,
                info:        item.info,
                infoMessage: item.info_message,
                cell:        (row) => fmt(row[item.field], item.field, 'excluded'),
            });
        });

        return [...baseColumns, ...excludeFieldsHeader, ...buildHeaderColumns(inputData, filterValues, [], columnData.length, 1)];
    }

    const dynamicColumns = values
        .filter((v) => v.hasShow !== false)
        .map(({field, lable, childField, action, expression, info, info_message}) => {
            let finalHeader = lable;
            if (operatorLength > 0) {
                const parentValue = parentData && Array.isArray(parentData) && parentData.length > 0 && parentData[0][field] !== undefined
                    ? parentData[0][field]
                    : null;
                if (shouldShowTotalBracket(field)) {
                    finalHeader = `${lable} [${fmt(parentValue, field, 'column')}]`;
                }
            } else {
                const computedHeader = calculateHeaderAggregate({field, action, expression, lable}, (row, targetField) => row[targetField], 'column');
                if (computedHeader) {
                    finalHeader = computedHeader;
                }
            }

            return {
                header:      finalHeader,
                info:        info,
                infoMessage: info_message,
                cell:        (row) => {
                    const formattedValue = fmt(row[field], field, 'column');
                    const childValue     = childField ? row[childField] : null;

                    return childValue !== null && childValue !== undefined ? `${formattedValue} (${childValue})` : formattedValue;
                },
            };
        });

    return [...baseColumns, ...dynamicColumns];
};

/* ------------------------------------------------ formatCellValue.ts */

StatusiReport.prototype.formatCellValue = function (value, field, type) {
    const rows       = this.rows;
    const columnData = this.columnData;
    const values     = this.values;
    let fieldConfig;

    if (type === 'rows' && (value === 0 || value === '0')) {
        return 'N/A';
    }

    if (type === 'rows') {
        fieldConfig = rows.find((item) => item.field === field);
    } else if (type === 'columnHeader') {
        fieldConfig = columnData.find((item) => item.field === field);
    } else {
        fieldConfig = values.find((item) => item.field === field);
    }

    const format = fieldConfig && fieldConfig.format;
    if (!format) {
        return value;
    }

    const formatType = Object.keys(format).find((key) => !!format[key]);
    if (!formatType) {
        return value;
    }

    if ((field.includes('date') || field.includes('activity_date')) && moment(value).isValid()) {
        return moment(value).format('YYYY-MM-DD');
    }

    switch (formatType) {
        case 'number': {
            const parsedValue = Number(value);
            if (isNaN(parsedValue) || parsedValue === 0 || parsedValue === null) {
                return '-';
            }

            return parsedValue.toLocaleString('en-US', {
                minimumFractionDigits: format.number.minFraction || 0,
                maximumFractionDigits: format.number.maxFraction || 2,
                useGrouping:           format.number.useGrouping !== false,
            });
        }
        case 'percentage': {
            const parsedValue = Number(value);
            if (isNaN(parsedValue) || parsedValue === 0 || parsedValue === null) {
                return '-';
            }
            const decimalPlaces = (format.percentage && format.percentage.decimalPlaces) ?? 2;
            const suffix        = (format.percentage && format.percentage.suffix) ?? '%';
            // multiply: false -> value is already a percentage (25 -> 25%), default treats it as a fraction
            const multiplier    = format.percentage && format.percentage.multiply === false ? 1 : 100;

            return (parsedValue * multiplier).toFixed(decimalPlaces) + suffix;
        }
        case 'currency': {
            const parsedValue = Number(value) || 0;

            return parsedValue.toLocaleString('en-US', {
                style:                 'currency',
                currency:              format.currency.currency || 'USD',
                maximumFractionDigits: format.currency.maximumFractionDigits || 2,
            });
        }
        case 'accounting': {
            const parsedValue = Number(value) || 0;

            return parsedValue < 0
                ? `(${Math.abs(parsedValue).toLocaleString('en-US', {minimumFractionDigits: 2})})`
                : parsedValue.toLocaleString('en-US', {minimumFractionDigits: 2});
        }
        case 'longDate': {
            const date = new Date(value);
            if (isNaN(date.getTime())) {
                return value;
            }

            return date.toLocaleDateString('en-US', {weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC'});
        }
        case 'shortDate': {
            const date = new Date(value);
            if (isNaN(date.getTime())) {
                return value;
            }

            return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
        }
        case 'time': {
            const date = new Date(value);
            if (isNaN(date.getTime())) {
                return value;
            }

            return date.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'UTC'});
        }
        case 'yearMonth': {
            const [year, month] = String(value).split('-');
            if (!year || !month) {
                return value;
            }
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const monthIndex = parseInt(month, 10) - 1;
            if (monthIndex < 0 || monthIndex > 11) {
                return value;
            }

            return `${monthNames[monthIndex]}, ${year}`;
        }
        case 'weekToFormat': {
            const [weekStr, yearStr] = String(value).split('-');
            const week = parseInt(weekStr, 10);
            const year = parseInt(yearStr, 10);
            if (!isNaN(week) && !isNaN(year)) {
                const isoDate = moment().isoWeekYear(year).isoWeek(week);
                let startDate = isoDate.clone().startOf('isoWeek');
                let endDate   = isoDate.clone().endOf('isoWeek');
                if (startDate.year() < year) {
                    startDate = moment(`${year}-01-01`);
                }
                if (endDate.year() > year) {
                    endDate = moment(`${year}-12-31`);
                }

                return `${startDate.format('MMM D')} - ${endDate.format('MMM D')}, ${year}`;
            }

            return value;
        }
        case 'dayFormatting': {
            const dayCount = Number(value);

            return isNaN(dayCount) ? value : `${dayCount} Days`;
        }
        case 'digitToMonth': {
            const index = Number(value);
            if (isNaN(index)) {
                return value;
            }
            if (index === 0) {
                return 'Month Of Acquire';
            }
            const suffix = index === 1 ? 'st' : index === 2 ? 'nd' : index === 3 ? 'rd' : 'th';

            return `${index}${suffix} Month`;
        }
        case 'dayFormat': {
            const [year, month, day] = String(value).split('-');
            if (!year || !month || !day) {
                return value;
            }
            const date = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
            if (isNaN(date.getTime())) {
                return value;
            }

            return date.toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC'});
        }
        case 'dateTime': {
            const date = new Date(value);
            if (isNaN(date.getTime())) {
                return value;
            }
            const pad = (n) => String(n).padStart(2, '0');

            return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}`;
        }
        case 'campaignType': {
            switch (value) {
                case 'D':
                    return 'Rental';
                case 'N':
                    return 'Newsletter';
                case 'O':
                    return 'Offer';
                default:
                    return 'Others';
            }
        }
        case 'alex_source': {
            switch (String(value)) {
                case 'a':
                    return '5X5 Set A';
                case 'b':
                    return '5X5 Set B';
                case '0':
                    return 'Seeds';
                default:
                    return value;
            }
        }
        case 'text':
            return String(value ?? '-');
        case 'shogun_domain': {
            const match = String(value ?? '').match(/"([^"]+)"/);

            return match ? match[1] : value;
        }
        default:
            return value === null || value === undefined || value === '' ? 0 : value;
    }
};

// Port of nestedValues() (statusi_ui/src/utils/reportUtils)
function nestedValues(keys, field, data) {
    let tempData = data;
    for (const key of keys) {
        if (tempData && typeof tempData === 'object' && key in tempData) {
            tempData = tempData[key];
        } else {
            return 0;
        }
    }

    return (tempData && tempData[field] !== undefined && tempData[field] !== null) ? tempData[field] : 0;
}
})();
