define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            //升级日志
            Table.api.init({
                extend: {
                    index_url: 'upgrade/logs',
                    del_url: 'upgrade/del',
                }
            });

            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'batch',
                sortName: 'created_at',
                sortOrder: 'desc',
                //服务端已 htmlspecialchars，前端再转义会变成 &amp;quot;
                escape: false,
                //日志来自文件系统扫描，未实现服务端检索
                search: false,
                commonSearch: false,
                //工具栏只保留刷新按钮
                showColumns: false,
                showToggle: false,
                showExport: false,
                columns: [[
                    {
                        // 占位字段名必须不在行数据里：bootstrap-table 会把复选框列的 field 当 stateField，并覆写成布尔值
                        field: 'checked', checkbox: true
                    },
                    {
                        field: 'created_at', title: __('Start time'),
                        formatter: function (value, row) {
                            return value + '<br><small class="text-muted">' + row.batch + '</small>';
                        }
                    },
                    {
                        field: 'from_version', title: __('Version'),
                        formatter: function (value, row) {
                            return value + ' <i class="fa fa-long-arrow-right" aria-hidden="true"></i> <strong>' + row.to_version + '</strong>';
                        }
                    },
                    {
                        field: 'state', title: __('Status'),
                        formatter: function (value) {
                            return '<span class="label ' + Controller.api.stateClass(value) + '">' + __(value) + '</span>';
                        }
                    },
                    {
                        field: 'sql_total', title: __('SQL progress'),
                        formatter: function (value, row) {
                            return value > 0 ? row.sql_completed + '/' + value : '—';
                        }
                    },
                    {
                        field: 'message', title: __('Result'),
                        formatter: function (value) {
                            return value || '—';
                        }
                    },
                    {
                        field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,
                        formatter: function (value, row, index) {
                            // 非终态批次不给删除入口：它们的数据库备份与回滚日志必须保留
                            if (Controller.api.deletableStates.indexOf(row.state) === -1) {
                                return '';
                            }
                            return Table.api.formatter.operate.call(this, value, row, index);
                        }
                    },
                ]]
            });
            Table.api.bindevent(table);

            var form = $("#upgrade-form");
            var $preview = $("#upgrade-preview");
            var $check = $(".btn-check");
            var $run = $(".btn-run");
            var latestVersion = "";
            var busy = false;
            var pollTimer = null;
            var pollAttempts = 0;
            var seenRunning = false;

            var formData = function (data) {
                data.__token__ = form.find("input[name='__token__']").val();
                return data;
            };

            // 响应头返回刷新后的 token，回填以便连续操作
            var refreshToken = function (xhr) {
                var token = xhr.getResponseHeader("__token__");
                if (token) {
                    $("input[name='__token__']").val(token);
                }
            };

            // 按 DOM 里 data-state 的顺序定位当前步骤，避免与模板顺序脱节
            var renderProgress = function (state, sqlCompleted, sqlTotal) {
                var $steps = $(".upgrade-progress-step");
                var index = -1;
                $steps.each(function (i) {
                    if ($(this).data("state") === state) {
                        index = i;
                    }
                });
                // 恢复现场等不在步骤链里的状态，交给告警文字表达
                if (index === -1) {
                    $(".upgrade-progress").addClass("hide");
                    return;
                }
                $(".upgrade-progress").removeClass("hide");
                $steps.each(function (i) {
                    $(this).removeClass("is-done is-active")
                        .addClass(i < index ? "is-done" : (i === index ? "is-active" : ""));
                });
                // 执行 SQL 耗时最长且无上限，用真实条数代替按步骤均分
                var ratio = (state === "running_sql" && sqlTotal > 0)
                    ? Math.min(sqlCompleted / sqlTotal, 1) : 1;
                $(".upgrade-progress-percent").text(Math.round((index + ratio) / $steps.length * 100) + "%");
            };

            var stopPolling = function () {
                if (pollTimer) {
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                }
            };

            var startPolling = function () {
                if (pollTimer) {
                    return;
                }
                pollAttempts = 0;
                // 最多轮询 30 分钟，异常时不做无限请求
                pollTimer = window.setInterval(function () {
                    if (++pollAttempts > 900) {
                        stopPolling();
                        return;
                    }
                    $.ajax({
                        url: Fast.api.fixurl("upgrade/status"),
                        dataType: "json",
                        cache: false
                    }).done(function (data) {
                        if (!data || !data.state) {
                            // 批次已结束；确认跑起来过才刷新，避免与刚发出的升级请求抢跑
                            if (seenRunning) {
                                stopPolling();
                                window.location.reload();
                            }
                            return;
                        }
                        seenRunning = true;
                        renderProgress(data.state, data.sql_completed, data.sql_total);
                        if ($.inArray(data.state, ['done', 'failed', 'failed_rolled_back', 'needs_repair']) !== -1) {
                            stopPolling();
                            window.location.reload();
                        }
                    }).fail(function () {
                        stopPolling();
                    });
                }, 2000);
            };

            var renderPreview = function (data) {
                var available = !!data.available;
                latestVersion = available ? data.latest_version : "";
                $preview.removeClass("hide");
                $preview.find(".preview-version").text(data.latest_version || "-");
                $preview.find("#upgrade-notes").text(data.notes || "-");
                var manualUrl = Controller.api.webUrl(data.download_url);
                $preview.find(".upgrade-manual").toggleClass("hide", !available || !manualUrl);
                $preview.find(".upgrade-manual-url").attr("href", manualUrl);
                $preview.find(".upgrade-manual-version").text(data.latest_version || "");
                $preview.find(".preview-state")
                    .removeClass("label-success label-warning")
                    .addClass(available ? "label-warning" : "label-success")
                    .html('<i class="fa ' + (available ? 'fa-cloud-download' : 'fa-check-circle')
                        + '" aria-hidden="true"></i> ' + (available ? __('New version found') : __('Already latest')));
                $run.toggleClass("hide", !available).prop("disabled", !available);
            };

            // 检查更新
            $(document).on("click", ".btn-check", function () {
                if (busy) {
                    return false;
                }
                busy = true;
                $check.prop("disabled", true);
                Fast.api.ajax({
                    url: "upgrade/check",
                    complete: function () {
                        busy = false;
                        $check.prop("disabled", false);
                    }
                }, function (data) {
                    renderPreview(data);
                    return false;
                });
                return false;
            });

            // 执行升级
            $(document).on("click", ".btn-run", function () {
                if (busy || !latestVersion || $(this).prop("disabled")) {
                    return false;
                }
                Layer.confirm(__('Confirm upgrade tips'), { title: __('Warmtips') }, function (index) {
                    Layer.close(index);
                    busy = true;
                    $check.prop("disabled", true);
                    $run.prop("disabled", true)
                        .html('<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> ' + __('Upgrade in progress'));
                    startPolling();
                    Fast.api.ajax({
                        url: "upgrade/run",
                        data: formData({ version: latestVersion }),
                        complete: function (xhr) {
                            refreshToken(xhr);
                            window.setTimeout(function () {
                                window.location.reload();
                            }, 1000);
                        }
                    }, function (data, ret) {
                        $("#upgrade-status").removeClass("hide").text(ret.msg || __('Upgrade completed'));
                        return false;
                    });
                });
                return false;
            });

            $(document).on("click", ".btn-recover", function () {
                if (busy) {
                    return false;
                }
                var batch = $(this).data("batch");
                Layer.confirm(__('Confirm recovery tips'), { title: __('Warmtips') }, function (index) {
                    Layer.close(index);
                    busy = true;
                    Fast.api.ajax({
                        url: "upgrade/recover",
                        data: formData({ batch: batch }),
                        complete: function (xhr) {
                            refreshToken(xhr);
                            window.setTimeout(function () {
                                window.location.reload();
                            }, 1000);
                        }
                    }, function (data, ret) {
                        $("#upgrade-status").removeClass("hide").text(ret.msg || __('Recovery completed'));
                        return false;
                    });
                });
                return false;
            });

            // 复制下载地址：execCommand 不依赖安全上下文，比 clipboard API 稳
            $(document).on("click", ".btn-copy-url", function () {
                // prop("href") 是浏览器解析后的绝对地址，站点根相对路径也能拿到完整 URL
                var url = $(this).closest(".upgrade-manual").find(".upgrade-manual-url").prop("href") || "";
                if (!url) {
                    return false;
                }
                var $holder = $("<textarea>").val(url)
                    .css({ position: "fixed", top: "-1000px", opacity: 0 }).appendTo("body");
                $holder[0].select();
                var copied = false;
                try {
                    copied = document.execCommand("copy");
                } catch (e) {
                    copied = false;
                }
                $holder.remove();
                Layer.msg(copied ? __('Copy success') : url);
                return false;
            });

            // 页面加载时批次仍在执行，直接接上进度
            var $running = $(".alert[data-state][data-recoverable='0']");
            if ($running.length) {
                renderProgress($running.data("state"));
                startPolling();
            }

            // 中断告警里的下载链接由服务端渲染；地址不可链接时整块隐藏
            $(".upgrade-manual").each(function () {
                var $link = $(this).find(".upgrade-manual-url");
                var url = Controller.api.webUrl($link.attr("href"));
                $(this).toggleClass("hide", !url);
                $link.attr("href", url);
            });

            Controller.api.bindevent();
        },
        check: function () {
        },
        run: function () {
        },
        recover: function () {
        },
        api: {
            // 仅终态批次可删；进行中与待修复的批次必须保留备份
            deletableStates: ['done', 'failed', 'failed_rolled_back'],
            // 只有 http(s)、协议相对与站点根相对地址能当下载链接用
            // 裸相对路径服务端是按项目根解析的，不在 public 下，没有对应 web 地址
            webUrl: function (url) {
                var value = String(url || "").trim();
                if (value === "") {
                    return "";
                }
                if (value.charAt(0) === "/" || /^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(value)) {
                    return value;
                }
                return "";
            },
            stateClass: function (state) {
                if (state === 'done') {
                    return 'label-success';
                }
                if ($.inArray(state, ['failed', 'failed_rolled_back', 'needs_repair']) !== -1) {
                    return 'label-danger';
                }
                return 'label-info';
            },
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
