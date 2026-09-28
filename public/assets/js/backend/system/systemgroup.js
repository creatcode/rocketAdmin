define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'system.systemgroup/index',
                    add_url: 'system.systemgroup/add',
                    edit_url: 'system.systemgroup/edit',
                    del_url: 'system.systemgroup/del',
                    multi_url: 'system.systemgroup/multi',
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
                                    url: 'system.systemgroupdata/index?group_id={id}'
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
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                var form = $("form[role=form]");
                var fieldlist = $(".fieldlist", form);
                //按字段类型显示对应的限制和选项
                var refreshLimit = function (row) {
                    var type = $("select[name$='[type]']", row).val();
                    $("input[name$='[maxlength]']", row).toggle(type === 'string' || type === 'text');
                    $("input[name$='[min]'],input[name$='[max]']", row).toggle(type === 'number');
                    $("textarea[name$='[options]']", row).toggle(type === 'select');
                };
                fieldlist.on('change', "select[name$='[type]']", function () {
                    refreshLimit($(this).closest('tr'));
                });
                fieldlist.on('fa.event.appendfieldlist', function (e, row) {
                    refreshLimit(row);
                });
                Form.api.bindevent(form);
            }
        }
    };
    return Controller;
});
