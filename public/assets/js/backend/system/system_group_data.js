define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            var group = Config.group || {};
            var fields = Config.fields || [];
            //记录列表始终限定在当前数据组内
            var groupQuery = '?group_id=' + group.id;
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'system.system_group_data/index' + groupQuery,
                    add_url: 'system.system_group_data/add' + groupQuery,
                    edit_url: 'system.system_group_data/edit' + groupQuery,
                    del_url: 'system.system_group_data/del' + groupQuery,
                    multi_url: 'system.system_group_data/multi' + groupQuery,
                    table: 'system_group_data'
                }
            });

            var table = $("#table");
            var columns = [
                {field: 'state', checkbox: true},
                {field: 'id', title: 'ID'}
            ];
            //根据字段定义生成列,动态字段保存在JSON中,不参与搜索、筛选和排序
            $.each(fields, function (i, field) {
                columns.push({
                    field: 'value.' + field.name,
                    title: field.title,
                    operate: false,
                    sortable: false,
                    formatter: Controller.api.formatter.value(field)
                });
            });
            columns.push({field: 'weigh', title: __('Weigh')});
            columns.push({
                field: 'status', title: __('Status'),
                searchList: {"normal": __('Normal'), "hidden": __('Hidden')},
                formatter: Table.api.formatter.status
            });
            columns.push({
                field: 'operate', title: __('Operate'), table: table,
                events: Table.api.events.operate, formatter: Table.api.formatter.operate
            });

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [columns]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            formatter: {
                value: function (field) {
                    return function (value, row, index) {
                        value = value === null || typeof value === 'undefined' ? '' : value.toString();
                        if (field.type === 'image') {
                            return value === '' ? '' : '<a href="' + Fast.api.cdnurl(value) + '" target="_blank"><img src="' + Fast.api.cdnurl(value) + '" class="img-sm img-center"/></a>';
                        }
                        if (field.type === 'uploads') {
                            if (value === '') return '';
                            var html = '';
                            $.each(value.split(','), function (i, path) {
                                html += '<a href="' + Fast.api.cdnurl(path) + '" target="_blank"><img src="' + Fast.api.cdnurl(path) + '" class="img-sm img-center"/></a> ';
                            });
                            return html;
                        }
                        if (field.type === 'switch') {
                            return value === '1' ? __('Yes') : __('No');
                        }
                        if (field.type === 'select' || field.type === 'radio') {
                            value = field.param && typeof field.param[value] !== 'undefined' ? Fast.api.escape(field.param[value]) : value;
                        }
                        if (field.type === 'checkbox') {
                            var keys = value === '' ? [] : value.split(',');
                            value = $.map(keys, function (k) {
                                return field.param && typeof field.param[k] !== 'undefined' ? field.param[k] : k;
                            }).join(',');
                            return Fast.api.escape(value);
                        }
                        return Table.api.formatter.content.call(this, value, row, index);
                    };
                }
            }
        }
    };
    return Controller;
});
