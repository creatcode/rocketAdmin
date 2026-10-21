define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'template'], function ($, undefined, Backend, Table, Form, Template, undefined) {
    var Controller = {
        index: function () {
            var sourceUrl = Config.api_url || '';
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: sourceUrl && /\.json(\?|$)/i.test(sourceUrl) ? "addon/online" : "addon/downloaded",
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: ''
                }
            });

            var table = $("#table");

            // 弹窗自适应宽高
            var area = Fast.config.openArea != undefined ? Fast.config.openArea : [$(window).width() > 800 ? '800px' : '95%', $(window).height() > 600 ? '600px' : '95%'];

            //空消息兜底，避免弹窗无文案
            var errMsg = function (ret) {
                return ret && ret.msg ? ret.msg : __('Addon operate failed');
            };

            //渲染体检报告，确认后继续
            var showReport = function (report, onContinue) {
                var html = '';
                if (report.undeclared) {
                    html += '<p class="text-warning">' + __('Addon check undeclared') + '</p>';
                }
                if (report.issues && report.issues.length) {
                    html += '<p>' + __('Addon check found', report.errors, report.warnings) + '</p>';
                    html += '<ul style="max-height:260px;overflow-y:auto;padding-left:18px;">';
                    $.each(report.issues.slice(0, 20), function (i, item) {
                        html += '<li>[' + (item.level === 'error' ? __('Addon check error') : __('Addon check warning')) + '] '
                            + Fast.api.escape(item.file) + ':' + item.line + ' ' + Fast.api.escape(item.message) + '</li>';
                    });
                    html += '</ul>';
                }
                Layer.confirm(html, {
                    title: __('Addon compatibility check'),
                    area: ['640px', '420px'],
                    btn: [__('Continue install'), __('Cancel')]
                }, function (index) {
                    layer.close(index);
                    onContinue();
                });
            };

            var sourceError = function () {
                Layer.confirm(__('Addon source unavailable'), {
                    title: __('Warmtips'),
                    btn: [__('OK'), __('try to reload')]
                }, function (index) {
                    layer.close(index);
                }, function (index) {
                    layer.close(index);
                    table.bootstrapTable('refresh');
                });
            };
            table.on('load-success.bs.table', function (e, json) {
                if (json && typeof json.rows === 'undefined' && typeof json.code !== 'undefined') {
                    sourceError();
                }
            });
            table.on('load-error.bs.table', sourceError);
            table.on('post-body.bs.table', function (e, settings, json, xhr) {
                var parenttable = table.closest('.bootstrap-table');
                var d = $(".fixed-table-toolbar", parenttable).find(".search input");
                d.off("keyup drop blur");
                d.on("keyup", function (e) {
                    if (e.keyCode == 13) {
                        var that = this;
                        var options = table.bootstrapTable('getOptions');
                        var queryParams = options.queryParams;
                        options.pageNumber = 1;
                        options.queryParams = function (params) {
                            var params = queryParams(params);
                            params.search = $(that).val();
                            return params;
                        };
                        table.bootstrapTable('refresh', {});
                    }
                });
            });

            Template.helper("Moment", Moment);
            Template.helper("addons", Config['addons']);

            $("#faupload-addon").data("params", function (files, xhr) {
                return {
                    version: Config.faversion,
                    faversion: Config.faversion,
                    force: (files[0].force || false) ? 1 : 0,
                    skipcheck: (files[0].skipcheck || false) ? 1 : 0
                };
            });

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pageSize: 50,
                columns: [
                    [
                        {field: 'id', title: 'ID', operate: false, visible: false},
                        {
                            field: 'home',
                            title: __('Index'),
                            width: '50px',
                            formatter: Controller.api.formatter.home
                        },
                        {field: 'name', title: __('Name'), operate: false, visible: false, width: '120px'},
                        {
                            field: 'title',
                            title: __('Title'),
                            operate: 'LIKE',
                            align: 'left',
                            formatter: Controller.api.formatter.title
                        },
                        {
                            field: 'intro',
                            title: __('Intro'),
                            operate: 'LIKE',
                            align: 'left',
                            class: 'visible-lg',
                            formatter: Controller.api.formatter.intro
                        },
                        {
                            field: 'author',
                            title: __('Author'),
                            operate: 'LIKE',
                            width: '140px',
                            formatter: Controller.api.formatter.author
                        },
                        {
                            field: 'version',
                            title: __('Version'),
                            operate: 'LIKE',
                            width: '80px',
                            align: 'center',
                            formatter: Controller.api.formatter.version
                        },
                        {
                            field: 'toggle',
                            title: __('Status'),
                            width: '80px',
                            formatter: Controller.api.formatter.toggle
                        },
                        {
                            field: 'id',
                            title: __('Operate'),
                            table: table,
                            formatter: Controller.api.formatter.operate,
                            align: 'right',
                            cellStyle: function (value, row, index) {
                                return {css: {'min-width': '158px'}};
                            }
                        },
                    ]
                ],
                responseHandler: function (res) {
                    $.each(res.rows, function (i, j) {
                        j.addon = typeof Config.addons[j.name] != 'undefined' ? Config.addons[j.name] : null;
                    });
                    return res;
                },
                dataType: 'jsonp',
                templateView: false,
                clickToSelect: false,
                search: true,
                showColumns: false,
                showToggle: false,
                showExport: false,
                showSearch: false,
                commonSearch: true,
                searchFormVisible: true,
                searchFormTemplate: 'searchformtpl',
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 离线安装
            require(['upload'], function (Upload) {
                Upload.api.upload("#faupload-addon", function (data, ret, up, file) {
                    Config['addons'][data.addon.name] = data.addon;
                    var addon = data.addon;
                    var testdata = data.addon.testdata;
                    operate(data.addon.name, 'enable', false, function (data, ret) {
                        Layer.alert(__('Offline installed tips') + (testdata ? __('Testdata tips') : ""), {
                            btn: testdata ? [__('Import testdata'), __('Skip testdata')] : [__('OK')],
                            title: __('Warning'),
                            yes: function (index) {
                                if (testdata) {
                                    Fast.api.ajax({
                                        url: 'addon/testdata',
                                        data: {
                                            name: addon.name,
                                            version: addon.version,
                                            faversion: Config.faversion
                                        }
                                    }, function (data, ret) {
                                        Layer.close(index);
                                    });
                                } else {
                                    Layer.close(index);
                                }
                            },
                            icon: 1
                        });
                    });
                    return false;
                }, function (data, ret, up, file) {
                    if (ret && ret.code === -4) {
                        showReport(ret.data.report, function () {
                            up.removeFile(file);
                            file.skipcheck = true;
                            up.uploadFile(file);
                        });
                        return false;
                    } else if (ret.code === -1) {
                        Layer.confirm(__('Upgrade tips', data.title), {title: __('Warmtips')}, function (index, layero) {
                            up.removeFile(file);
                            file.force = true;
                            up.uploadFile(file);
                            Layer.close(index);
                        });
                        return false;
                    } else if (ret && ret.code === -3) {
                        //插件目录发现影响全局的文件
                        Layer.open({
                            content: Template("conflicttpl", ret.data),
                            shade: 0.8,
                            area: area,
                            title: __('Warning'),
                            btn: [__('Continue install'), __('Cancel')],
                            end: function () {

                            },
                            yes: function (index) {
                                up.removeFile(file);
                                file.force = true;
                                up.uploadFile(file);
                                Layer.close(index);
                            }
                        });

                    } else {
                        Layer.alert(errMsg(ret), {title: __('Warning'), icon: 0});
                    }
                });

            });

            // 查看插件首页
            $(document).on("click", ".btn-addonindex", function () {
                if ($(this).attr("href") == 'javascript:;') {
                    Layer.msg(__('Not installed tips'), {icon: 7});
                } else if ($(this).closest(".operate").find("a.btn-enable").length > 0) {
                    Layer.msg(__('Not enabled tips'), {icon: 7});
                    return false;
                }
            });

            var tables = [];
            $(document).on("click", "#droptables", function () {
                if ($(this).prop("checked")) {
                    Fast.api.ajax({
                        url: "addon/get_table_list",
                        async: false,
                        data: {name: $(this).data("name")}
                    }, function (data) {
                        tables = data.tables;
                        return false;
                    });
                    var html;
                    html = tables.length > 0 ? '<div class="alert alert-warning-light droptablestips" style="max-width:480px;max-height:300px;overflow-y: auto;">' + __('The following data tables will be deleted') + '：<br>' + tables.join("<br>") + '</div>'
                        : '<div class="alert alert-warning-light droptablestips">' + __('The Addon did not create a data table') + '</div>';
                    $(html).insertAfter($(this).closest("p"));
                } else {
                    $(".droptablestips").remove();
                }
                $(window).resize();
            });

            var install = function (name, version, force, skipcheck) {
                Fast.api.ajax({
                    url: 'addon/install',
                    data: {
                        name: name,
                        force: force ? 1 : 0,
                        version: version,
                        faversion: Config.faversion,
                        skipcheck: skipcheck ? 1 : 0
                    }
                }, function (data, ret) {
                    Layer.closeAll();
                    Config['addons'][data.addon.name] = ret.data.addon;
                    operate(data.addon.name, 'enable', false, function () {
                        Layer.alert(__('Online installed tips') + (data.addon.testdata ? __('Testdata tips') : ""), {
                            btn: data.addon.testdata ? [__('Import testdata'), __('Skip testdata')] : [__('OK')],
                            title: __('Warning'),
                            yes: function (index) {
                                if (data.addon.testdata) {
                                    Fast.api.ajax({
                                        url: 'addon/testdata',
                                        data: {
                                            name: name,
                                            version: version,
                                            faversion: Config.faversion
                                        }
                                    }, function (data, ret) {
                                        Layer.close(index);
                                    });
                                } else {
                                    Layer.close(index);
                                }
                            },
                            icon: 1
                        });
                        Controller.api.refresh(table, name);
                    });
                }, function (data, ret) {
                    var area = Fast.config.openArea != undefined ? Fast.config.openArea : [$(window).width() > 650 ? '650px' : '95%', $(window).height() > 710 ? '710px' : '95%'];
                    if (ret && ret.code === -4) {
                        showReport(ret.data.report, function () {
                            install(name, version, force, true);
                        });
                        return false;
                    }
                    if (ret && ret.code === -3) {
                        //插件目录发现影响全局的文件
                        Layer.open({
                            content: Template("conflicttpl", ret.data),
                            shade: 0.8,
                            area: area,
                            title: __('Warning'),
                            btn: [__('Continue install'), __('Cancel')],
                            end: function () {

                            },
                            yes: function () {
                                install(name, version, true);
                            }
                        });

                    } else {
                        Layer.alert(errMsg(ret), {title: __('Warning'), icon: 0});
                    }
                    return false;
                });
            };

            var uninstall = function (name, force, droptables) {
                Fast.api.ajax({
                    url: 'addon/uninstall',
                    data: {name: name, force: force ? 1 : 0, droptables: droptables ? 1 : 0}
                }, function (data, ret) {
                    delete Config['addons'][name];
                    Layer.closeAll();
                    Controller.api.refresh(table, name);
                }, function (data, ret) {
                    if (ret && ret.code === -3) {
                        //插件目录发现影响全局的文件
                        Layer.open({
                            content: Template("conflicttpl", ret.data),
                            shade: 0.8,
                            area: area,
                            title: __('Warning'),
                            btn: [__('Continue uninstall'), __('Cancel')],
                            end: function () {

                            },
                            yes: function () {
                                uninstall(name, true, droptables);
                            }
                        });

                    } else {
                        Layer.alert(errMsg(ret), {title: __('Warning'), icon: 0});
                    }
                    return false;
                });
            };

            var operate = function (name, action, force, success) {
                Fast.api.ajax({
                    url: 'addon/state',
                    data: {name: name, action: action, force: force ? 1 : 0}
                }, function (data, ret) {
                    var addon = Config['addons'][name];
                    addon.state = action === 'enable' ? 1 : 0;
                    Layer.closeAll();
                    if (typeof success === 'function') {
                        success(data, ret);
                    }
                    Controller.api.refresh(table, name);
                }, function (data, ret) {
                    if (ret && ret.code === -3) {
                        //插件目录发现影响全局的文件
                        Layer.open({
                            content: Template("conflicttpl", ret.data),
                            shade: 0.8,
                            area: area,
                            title: __('Warning'),
                            btn: [__('Continue operate'), __('Cancel')],
                            end: function () {

                            },
                            yes: function () {
                                operate(name, action, true, success);
                            }
                        });

                    } else {
                        Layer.alert(errMsg(ret), {title: __('Warning'), icon: 0});
                    }
                    return false;
                });
            };

            var upgrade = function (name, version, skipcheck) {
                Fast.api.ajax({
                    url: 'addon/upgrade',
                    data: {name: name, version: version, faversion: Config.faversion, skipcheck: skipcheck ? 1 : 0}
                }, function (data, ret) {
                    Config['addons'][name] = data.addon;
                    Layer.closeAll();
                    Controller.api.refresh(table, name);
                }, function (data, ret) {
                    if (ret && ret.code === -4) {
                        showReport(ret.data.report, function () {
                            upgrade(name, version, true);
                        });
                        return false;
                    }
                    Layer.alert(errMsg(ret), {title: __('Warning')});
                    return false;
                });
            };

            // 点击安装
            $(document).on("click", ".btn-install", function () {
                var name = $(this).closest(".operate").data("name");
                var version = $(this).data("version");
                var compatible = $(this).closest(".operate").data("compatible");


                //仅明确声明适配的插件直接安装，其余先确认且不阻止
                if (compatible !== 'ok') {
                    Layer.confirm(__('Undeclared compatibility tips'), {title: __('Warmtips')}, function (index) {
                        layer.close(index);
                        install(name, version, false);
                    });
                    return false;
                }
                install(name, version, false);
            });

            // 点击卸载
            $(document).on("click", ".btn-uninstall", function () {
                var name = $(this).closest(".operate").data('name');
                if (Config['addons'][name].state == 1) {
                    Layer.alert(__('Please disable the add before trying to uninstall'), {icon: 7});
                    return false;
                }
                Template.helper("__", __);
                tables = [];
                Layer.confirm(Template("uninstalltpl", {addon: Config['addons'][name]}), {focusBtn: false, title: __("Warning")}, function (index, layero) {
                    uninstall(name, false, $("input[name='droptables']", layero).prop("checked"));
                });
            });

            // 点击配置
            $(document).on("click", ".btn-config", function () {
                var name = $(this).closest(".operate").data("name");
                Fast.api.open("addon/config?name=" + name, __('Setting'));
            });

            // 点击启用/禁用
            $(document).on("click", ".btn-enable,.btn-disable", function () {
                var name = $(this).data("name");
                var action = $(this).data("action");
                operate(name, action, false);
            });

            // 点击升级
            $(document).on("click", ".btn-upgrade", function () {
                var name = $(this).closest(".operate").data('name');
                if (Config['addons'][name].state == 1) {
                    Layer.alert(__('Please disable the add before trying to upgrade'), {icon: 7});
                    return false;
                }
                var version = $(this).data("version");
                var compatible = $(this).closest(".operate").data("compatible");
                //未声明适配时在确认文案中附加提示，避免二次弹窗
                var tips = __('Upgrade tips', Config['addons'][name].title);
                if (compatible !== 'ok') {
                    tips += '<br><span class="text-warning">' + __('Undeclared compatibility tips') + '</span>';
                }

                Layer.confirm(tips, {title: __('Warmtips')}, function (index, layero) {
                    upgrade(name, version);
                });
            });

            $(document).on("click", ".operate .btn-group .dropdown-toggle", function () {
                $(this).closest(".btn-group").toggleClass("dropup", $(document).height() - $(this).offset().top <= 200);
            });

            $(document).on("click", ".view-screenshots", function () {
                var row = Table.api.getrowbyindex(table, parseInt($(this).data("index")));
                var data = [];
                $.each(row.screenshots, function (i, j) {
                    data.push({
                        "src": j
                    });
                });
                var json = {
                    "title": row.title,
                    "data": data
                };
                top.Layer.photos(top.JSON.parse(JSON.stringify({photos: json})));
            });
        },
        add: function () {
            Controller.api.bindevent();
        },
        config: function () {
            $(document).on("click", ".nav-group li a[data-toggle='tab']", function () {
                if ($(this).attr("href") == "#all") {
                    $(".tab-pane").addClass("active in");
                }
                return;
                var type = $(this).attr("href").substring(1);
                if (type == 'all') {
                    $(".table-config tr").show();
                } else {
                    $(".table-config tr").hide();
                    $(".table-config tr[data-group='" + type + "']").show();
                }
            });

            Controller.api.bindevent();
        },
        api: {
            formatter: {
                title: function (value, row, index) {
                    var inner = '<span class="' + Fast.api.escape(row.color) + '">' + value + '</span>';
                    var linkable = !!row.addon && row.has_front;
                    var title = linkable
                        ? '<a class="title" href="' + row.url + '" data-toggle="tooltip" title="' + __('View addon home page') + '" target="_blank">' + inner + '</a>'
                        : inner;
                    //已适配为默认状态不显示标签，仅对未声明适配的插件提示
                    if (row.compatible_state === 'undeclared') {
                        title += ' <span class="label label-warning">' + __('Undeclared compatibility') + '</span>';
                    }
                    if (row.screenshots && row.screenshots.length > 0) {
                        title += ' <a href="javascript:;" data-index="' + index + '" class="view-screenshots text-success" title="' + __('View addon screenshots') + '" data-toggle="tooltip"><i class="fa fa-image"></i></a>';
                    }
                    return title;
                },
                intro: function (value, row, index) {
                    return row.intro + (row.extend ? "<a href='" + Fast.api.escape(row.extend[1]) + "' class='" + Fast.api.escape(row.extend[2]) + "'>" + Fast.api.escape(row.extend[0]) + "</a>" : "");
                },
                operate: function (value, row, index) {
                    return Template("operatetpl", {item: row, index: index});
                },
                toggle: function (value, row, index) {
                    if (!row.addon) {
                        return '';
                    }
                    return '<a href="javascript:;" data-toggle="tooltip" title="' + __('Click to toggle status') + '" class="btn btn-toggle btn-' + (row.addon.state == 1 ? "disable" : "enable") + '" data-action="' + (row.addon.state == 1 ? "disable" : "enable") + '" data-name="' + row.name + '"><i class="fa ' + (row.addon.state == 0 ? 'fa-toggle-on fa-rotate-180 text-gray' : 'fa-toggle-on text-success') + ' fa-2x"></i></a>';
                },
                author: function (value, row, index) {
                    var url = 'javascript:';
                    if (typeof row.homepage !== 'undefined') {
                        url = row.homepage;
                    } else if (typeof row.qq !== 'undefined' && row.qq) {
                        url = 'https://wpa.qq.com/msgrd?v=3&uin=' + row.qq + '&site=&menu=yes';
                    }
                    return '<a href="' + url + '" target="_blank" data-toggle="tooltip" class="text-primary">' + value + '</a>';
                },
                version: function (value, row, index) {
                    if (row.addon && row.addon.version != row.version) {
                        return '<span class="releasetips text-primary" data-toggle="tooltip" title="' + __('New version found', row.version) + '">' + row.addon.version + '<i></i></span>';
                    }
                    return row.version;
                },
                home: function (value, row, index) {
                    //已安装且启用、并且存在前台入口的插件才可点击
                    var clickable = row.addon && parseInt(row.addon.state) > 0 && row.has_front !== false;
                    return clickable ? '<a href="' + row.addon.url + '" data-toggle="tooltip" title="' + __('View addon index page') + '" target="_blank"><i class="fa fa-home text-primary"></i></a>' : '<a href="javascript:;"><i class="fa fa-home text-gray"></i></a>';
                },
            },
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            refresh: function (table, name) {
                //刷新左侧边栏
                Fast.api.refreshmenu();

                //刷新行数据
                if ($(".operate[data-name='" + name + "']").length > 0) {
                    var tr = $(".operate[data-name='" + name + "']").closest("tr[data-index]");
                    var index = tr.data("index");
                    var row = Table.api.getrowbyindex(table, index);
                    row.addon = typeof Config['addons'][name] !== 'undefined' ? Config['addons'][name] : undefined;
                    table.bootstrapTable("updateRow", {index: index, row: row});
                } else {
                    $(".btn-refresh").trigger("click");
                }
            }
        }
    };
    return Controller;
});
