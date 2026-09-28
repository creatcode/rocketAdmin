define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            //升级日志
            Table.api.init({
                extend: {
                    index_url: 'system.upgrade/logs',
                    del_url: 'system.upgrade/del',
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
                            var details = '<button type="button" class="btn btn-xs btn-info btn-upgrade-detail" data-batch="'
                                + row.batch + '">' + __('Upgrade details') + '</button> ';
                            // 非终态批次不给删除入口：它们的数据库备份与回滚日志必须保留
                            if (Controller.api.deletableStates.indexOf(row.state) === -1) {
                                return details;
                            }
                            return details + Table.api.formatter.operate.call(this, value, row, index);
                        }
                    },
                ]]
            });
            Table.api.bindevent(table);

            // 沿用现有弹窗与本地分页表格，日志内容统一由组件转义。
            $(document).on('click', '.btn-upgrade-detail', function () {
                var $button = $(this);
                if ($button.prop('disabled')) {
                    return false;
                }
                $button.prop('disabled', true);
                Fast.api.ajax({
                    url: 'system.upgrade/detail',
                    type: 'get',
                    data: {batch: $button.data('batch')},
                    complete: function () {
                        $button.prop('disabled', false);
                    }
                }, function (data) {
                    var $content = $('#upgrade-detail-template').clone().removeAttr('id').removeClass('hide').appendTo('body');
                    /**
                     * 更新同一弹窗的批次与备份信息，供首次加载和手动刷新共用。
                     * @param {Object} data 批次详情
                     * @returns {void}
                     */
                    var renderDetail = function (data) {
                        var backup = data.backup;
                        $content.find('.detail-batch').html(data.summary.batch);
                        $content.find('.detail-result').html(data.summary.message || '—');
                        $content.find('.detail-files-count').text(backup.files_count);
                        $content.find('.detail-files-size').text(backup.files_size.toLocaleString() + ' B');
                        $content.find('.detail-database-size').text(backup.database_size > 0
                            ? backup.database_size.toLocaleString() + ' B' : __('No database backup'));
                        $content.find('.detail-backup-error').text(backup.error).toggleClass('hide', !backup.error);
                        $content.find('.btn-export-backup').attr('data-batch', data.summary.batch)
                            .prop('disabled', !backup.exportable);
                    };
                    renderDetail(data);
                    Layer.open({
                        type: 1,
                        title: __('Upgrade details'),
                        area: [Math.min($(window).width() - 30, 1000) + 'px', '80%'],
                        content: $content,
                        success: function () {
                            $content.find('.btn-refresh-detail').on('click', function () {
                                var $refresh = $(this).prop('disabled', true);
                                Fast.api.ajax({
                                    url: 'system.upgrade/detail', type: 'get', loading: false,
                                    data: {batch: $content.find('.btn-export-backup').attr('data-batch')},
                                    complete: function () { $refresh.prop('disabled', false); }
                                }, function (updated) {
                                    if ($.contains(document, $content[0])) {
                                        renderDetail(updated);
                                        $content.find('.detail-sql-table').bootstrapTable('load', updated.sql_logs);
                                    }
                                    return false;
                                });
                            });
                            $content.find('.detail-sql-table').bootstrapTable({
                                data: data.sql_logs,
                                escape: true,
                                pagination: true,
                                sidePagination: 'client',
                                pageSize: 10,
                                search: false,
                                showRefresh: false,
                                showToggle: false,
                                showColumns: false,
                                formatNoMatches: function () { return __('No SQL details'); },
                                columns: [[
                                    {field: 'version', title: __('SQL version')},
                                    {field: 'file', title: __('SQL file')},
                                    {field: 'state', title: __('Status'), formatter: function (value) {
                                        return __('SQL ' + value);
                                    }},
                                    {field: 'started_at', title: __('Start time')},
                                    {field: 'finished_at', title: __('Finish time')},
                                    {field: 'message', title: __('Result')}
                                ]]
                            });
                        },
                        end: function () {
                            $content.find('.detail-sql-table').bootstrapTable('destroy');
                            $content.remove();
                        }
                    });
                    return false;
                });
                return false;
            });

            $(document).on('click', '.btn-export-backup', function () {
                if ($(this).prop('disabled')) {
                    return false;
                }
                var batch = $(this).attr('data-batch');
                Layer.confirm(__('Confirm backup export'), {title: __('Warmtips')}, function (index) {
                    Layer.close(index);
                    window.open(Fast.api.fixurl('system.upgrade/backup') + '?batch=' + encodeURIComponent(batch), '_blank', 'noopener');
                });
                return false;
            });

            var form = $("#upgrade-form");
            var $preview = $("#upgrade-preview");
            var $check = $(".btn-check");
            var $run = $(".btn-run");
            var latestVersion = "";
            var busy = false;
            var pollTimer = null;
            var pollAttempts = 0;
            var seenRunning = false;
            var currentBatch = "";
            var previousBatch = $('#upgrade-panel').attr('data-last-batch') || '0';
            var statusPending = false;
            var operationPending = false;

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
            /**
             * 展示用户阶段和实际 SQL 文件进度，不估算整体耗时百分比。
             * @param {string} state 当前批次状态
             * @param {number} sqlCompleted 已完成 SQL 文件数
             * @param {number} sqlTotal SQL 文件总数
             * @returns {void}
             */
            var renderProgress = function (state, sqlCompleted, sqlTotal) {
                var $steps = $(".upgrade-progress-step");
                var index = -1;
                $steps.each(function (i) {
                    if (String($(this).data("state")).split(" ").indexOf(state) !== -1) {
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
                        .addClass(i < index || state === "done" ? "is-done" : (i === index ? "is-active" : ""));
                });
                // 执行 SQL 耗时最长且无上限，用真实条数代替按步骤均分
                $(".upgrade-progress-current").text(__(state) + (state === "running_sql"
                    ? " · " + __('SQL progress') + ": " + (sqlCompleted || 0) + "/" + (sqlTotal || 0) : ""));
            };

            var stopPolling = function () {
                if (pollTimer) {
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                }
            };

            /**
             * 查询失败时保留现场和按钮锁定，避免把网络异常当作升级失败。
             * @returns {void}
             */
            var statusUnavailable = function () {
                stopPolling();
                $("#upgrade-result").removeClass("hide alert-info alert-success alert-danger").addClass("alert-warning");
                $(".result-title").text(__('Status unavailable'));
                $(".result-message").text("");
                $('.btn-recover').prop('disabled', true);
            };

            /**
             * 根据服务端状态展示结果和恢复入口，完成后只刷新日志。
             * @param {Object} data 已转义的批次状态
             * @returns {void}
             */
            var renderStatus = function (data) {
                currentBatch = data.batch;
                $('#upgrade-panel').attr('data-last-batch', currentBatch);
                seenRunning = true;
                renderProgress(data.state, data.sql_completed, data.sql_total);
                var terminal = $.inArray(data.state, ['done', 'failed', 'failed_rolled_back', 'needs_repair']) !== -1;
                var recoverable = !!data.recoverable;
                $('.btn-recover').prop('disabled', operationPending || !recoverable);
                busy = operationPending || !terminal || recoverable;
                $check.prop("disabled", busy);
                $run.prop("disabled", true).toggleClass("hide", terminal || recoverable);
                $("#upgrade-status").addClass("hide");
                $("#upgrade-result").removeClass("hide alert-info alert-warning alert-success alert-danger")
                    .addClass(recoverable ? 'alert-warning' : (terminal ? (data.state === 'done' ? 'alert-success' : 'alert-danger') : 'alert-info'));
                $(".result-title").text(recoverable && !terminal ? __('Upgrade interrupted') : __(data.state));
                $(".result-message").html(data.message || "");
                $(".result-counts").text((data.from_version || $('.current-version').first().text()) + " → " + data.to_version
                    + (terminal ? " · " + __('Files changed') + ": " + (data.files_total || 0) : "")
                    + " · " + __('SQL progress') + ": " + (data.sql_completed || 0) + "/" + (data.sql_total || 0));
                $("#upgrade-result .btn-upgrade-detail").removeClass("hide").attr("data-batch", currentBatch).data("batch", currentBatch);
                $("#upgrade-result .btn-recover").toggleClass("hide", !recoverable).attr("data-batch", currentBatch).data("batch", currentBatch);
                if (terminal || recoverable) {
                    stopPolling();
                    table.bootstrapTable('refresh', {});
                    if (data.state === 'done' && !recoverable) {
                        $('.current-version').text(data.to_version);
                        $preview.addClass('hide');
                    }
                    if (!recoverable) {
                        $('.alert[data-state]').addClass('hide');
                    }
                }
            };

            /**
             * 按已知批次查询最终结果，单次请求未结束时不重复查询。
             * @returns {void}
             */
            var readStatus = function () {
                if (statusPending) {
                    return;
                }
                statusPending = true;
                $('.btn-refresh-status').prop('disabled', true);
                $.ajax({
                    url: Fast.api.fixurl("system.upgrade/status"),
                    data: {batch: currentBatch, after: previousBatch},
                    dataType: "json",
                    cache: false
                }).done(function (data) {
                    if (!data) {
                        // 批次已结束；确认跑起来过才刷新，避免与刚发出的升级请求抢跑
                        if (seenRunning || !operationPending) {
                            statusUnavailable();
                        }
                        return;
                    }
                    if (!data.state) {
                        statusUnavailable();
                        return;
                    }
                    renderStatus(data);
                }).fail(statusUnavailable).always(function () {
                    statusPending = false;
                    $('.btn-refresh-status').prop('disabled', false);
                });
            };

            var startPolling = function () {
                if (pollTimer) {
                    return;
                }
                pollAttempts = 0;
                // 最多轮询 30 分钟，异常时不做无限请求
                pollTimer = window.setInterval(function () {
                    if (++pollAttempts > 900) {
                        statusUnavailable();
                        return;
                    }
                    readStatus();
                }, 2000);
            };

            $(document).on('click', '.btn-refresh-status', function () {
                startPolling();
                readStatus();
                return false;
            });
            $(document).on('click', '.btn-refresh-page', function () {
                window.location.reload();
            });
            $('a[href="#upgrade-logs-tab"]').on('shown.bs.tab', function () {
                table.bootstrapTable('resetView');
            });

            var renderPreview = function (data) {
                var available = !!data.available;
                $('#upgrade-placeholder').addClass('hide');
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
                $check.prop("disabled", true).find('.fa').removeClass('fa-search').addClass('fa-spinner fa-spin');
                $run.prop('disabled', true);
                Fast.api.ajax({
                    url: "system.upgrade/check",
                    complete: function () {
                        busy = false;
                        $check.prop("disabled", false).find('.fa').removeClass('fa-spinner fa-spin').addClass('fa-search');
                    }
                }, function (data) {
                    $("#upgrade-status").addClass('hide');
                    renderPreview(data);
                    return false;
                }, function (data, ret) {
                    latestVersion = "";
                    $("#upgrade-status").removeClass('hide alert-info').addClass('alert-warning').html(ret.msg);
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
                    operationPending = true;
                    previousBatch = $('#upgrade-panel').attr('data-last-batch') || '0';
                    currentBatch = "";
                    seenRunning = false;
                    $check.prop("disabled", true);
                    $run.prop("disabled", true);
                    $("#upgrade-result").removeClass("hide alert-warning alert-danger alert-success").addClass("alert-info");
                    $(".result-title").text(__('Waiting for batch'));
                    $(".result-counts, .result-message").text("");
                    $("#upgrade-result .btn-upgrade-detail, #upgrade-result .btn-recover").addClass("hide");
                    renderProgress('preparing');
                    startPolling();
                    Fast.api.ajax({
                        url: "system.upgrade/run",
                        loading: false,
                        data: formData({ version: latestVersion }),
                        complete: function (xhr) {
                            operationPending = false;
                            refreshToken(xhr);
                            if (currentBatch) {
                                readStatus();
                            }
                        }
                    }, function (data) {
                        currentBatch = data.batch;
                        return false;
                    }, function (data, ret) {
                        if (data && data.batch) {
                            currentBatch = data.batch;
                        } else if (ret.code === 0 && !currentBatch) {
                            stopPolling();
                            busy = false;
                            $check.prop('disabled', false);
                            $("#upgrade-result").removeClass('alert-info').addClass('alert-danger');
                            $('.result-title').text(__('failed'));
                            $('.result-message').html(ret.msg);
                        } else {
                            statusUnavailable();
                        }
                        return false;
                    });
                });
                return false;
            });

            $(document).on("click", ".btn-recover", function () {
                if (operationPending || $(this).prop('disabled')) {
                    return false;
                }
                var batch = $(this).data("batch");
                Layer.confirm(__('Confirm recovery tips'), { title: __('Warmtips') }, function (index) {
                    Layer.close(index);
                    busy = true;
                    operationPending = true;
                    currentBatch = batch;
                    $check.prop('disabled', true);
                    $('.btn-recover').prop('disabled', true);
                    renderProgress('recovering');
                    startPolling();
                    Fast.api.ajax({
                        url: "system.upgrade/recover",
                        loading: false,
                        data: formData({ batch: batch }),
                        complete: function (xhr) {
                            operationPending = false;
                            refreshToken(xhr);
                            $('.btn-recover').prop('disabled', false);
                            readStatus();
                        }
                    }, function () {
                        return false;
                    }, function () {
                        statusUnavailable();
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
                busy = true;
                startPolling();
                readStatus();
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
