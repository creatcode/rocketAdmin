define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'system.system_group/index',
                    add_url: 'system.system_group/add',
                    edit_url: 'system.system_group/edit',
                    del_url: 'system.system_group/del',
                    multi_url: 'system.system_group/multi',
                    table: 'system_group'
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [
                    [
                        {field: 'state', checkbox: true},
                        {field: 'id', title: 'ID'},
                        {field: 'name', title: __('Name')},
                        {field: 'title', title: __('Title')},
                        {field: 'tip', title: __('Tip'), operate: false},
                        {field: 'weigh', title: __('Weigh')},
                        {
                            field: 'status', title: __('Status'),
                            searchList: {"normal": __('Normal'), "hidden": __('Hidden')},
                            formatter: Table.api.formatter.status
                        },
                        {
                            field: 'createtime', title: __('Createtime'), formatter: Table.api.formatter.datetime,
                            operate: 'RANGE', addclass: 'datetimerange', sortable: true
                        },
                        {
                            field: 'operate', title: __('Operate'), table: table,
                            events: Table.api.events.operate, formatter: Table.api.formatter.operate,
                            buttons: [
                                {
                                    name: 'data',
                                    icon: 'fa fa-list',
                                    title: __('Manage data'),
                                    text: __('Manage data'),
                                    classname: 'btn btn-xs btn-primary btn-dialog',
                                    url: 'system.system_group_data/index?group_id={id}'
                                }
                            ]
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Form.api.bindevent($("form[role=form]"));
        },
        edit: function () {
            Form.api.bindevent($("form[role=form]"));
        }
    };
    return Controller;
});
