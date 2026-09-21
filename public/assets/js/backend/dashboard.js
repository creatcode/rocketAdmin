define(['jquery', 'bootstrap', 'backend', 'addtabs', 'table', 'echarts', 'echarts-theme', 'template'], function ($, undefined, Backend, Datatable, Table, Echarts, undefined, Template) {

    var Controller = {
        index: function () {
            var chartEl = document.getElementById('userTrendChart');
            if (!chartEl) {
                return;
            }

            var chart = Echarts.init(chartEl);
            var option = {
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: 'rgba(29,53,87,.92)',
                    borderWidth: 0,
                    textStyle: {
                        color: '#fff'
                    }
                },
                grid: {
                    left: 18,
                    // ECharts 4 的 containLabel 不为 boundaryGap:false 的末位标签预留右半宽，
                    // 参考实现用 18 会被裁掉半截日期，故加宽到 36
                    right: 36,
                    top: 36,
                    bottom: 18,
                    containLabel: true
                },
                xAxis: {
                    type: 'category',
                    boundaryGap: false,
                    data: Config.column || [],
                    axisLine: {
                        lineStyle: {
                            color: '#d5e2f3'
                        }
                    },
                    axisLabel: {
                        color: '#7f92aa'
                    },
                    axisTick: {
                        show: false
                    }
                },
                yAxis: {
                    type: 'value',
                    minInterval: 1,               // 注册数只能取整，避免出现 0.2 / 0.4 刻度
                    splitLine: {
                        lineStyle: {
                            color: 'rgba(126,156,203,.14)'
                        }
                    },
                    axisLine: {
                        show: false
                    },
                    axisLabel: {
                        color: '#7f92aa'
                    },
                    axisTick: {
                        show: false
                    }
                },
                series: [{
                    name: '新增会员',
                    type: 'line',
                    smooth: true,
                    symbol: 'circle',
                    symbolSize: 8,
                    data: Config.userdata || [],
                    lineStyle: {
                        width: 4,
                        color: '#6b93f1'
                    },
                    itemStyle: {
                        color: '#6b93f1',
                        borderColor: '#fff',
                        borderWidth: 2
                    },
                    areaStyle: {
                        color: new Echarts.graphic.LinearGradient(0, 0, 0, 1, [{
                            offset: 0,
                            color: 'rgba(107,147,241,.34)'
                        }, {
                            offset: 1,
                            color: 'rgba(107,147,241,.04)'
                        }])
                    }
                }]
            };

            chart.setOption(option);

            // 全 0 时给出空状态，避免渐变底容器被误读为渲染失败
            var hasData = (Config.userdata || []).some(function (v) {
                return Number(v) > 0;
            });
            if (!hasData) {
                var tip = document.createElement('div');
                tip.className = 'chart-box__empty';
                tip.textContent = '暂无数据';
                chartEl.appendChild(tip);
            }

            $(window).on('resize.dashboard', function () {
                chart.resize();
            });

            // 页签切换后容器尺寸变化，必须重算
            $(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function () {
                chart.resize();
            });
        }
    };

    return Controller;
});
