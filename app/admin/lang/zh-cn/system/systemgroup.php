<?php

return [
    // 页面
    'Fields'              => '字段定义',
    'Field name'          => '字段标识',
    'Field title'         => '字段标题',
    'Field type'          => '字段类型',
    'Field required'      => '必填',
    'Field limit'         => '限制/选项',
    'Group name tips'     => '小写字母开头,只能包含小写字母、数字和下划线,创建后不可修改',
    'Field name tips'     => '小写字母开头,只能包含小写字母、数字和下划线',
    'Field maxlength tips' => '文本最大长度',
    'Field min tips'      => '最小值',
    'Field max tips'      => '最大值',
    'Field options tips'  => '每行一个:值|显示名称',
    'Manage data'         => '管理数据',
    // 字段类型
    'String'              => '字符',
    'Text'                => '文本',
    'Number'              => '数字',
    'Switch'              => '开关',
    'Image'               => '图片',
    'Select'              => '列表',
    // 字段定义校验
    'Fields must be an array'                 => '字段定义必须是数组',
    'Field definition must be an object'      => '每个字段定义必须是对象',
    'Unknown field property %s'               => '字段定义包含未知属性:%s',
    'Field name %s is invalid'                => '字段标识 %s 不合法,必须以小写字母开头,只能包含小写字母、数字和下划线',
    'Field name %s is reserved'               => '字段标识 %s 是保留字段,不能使用',
    'Field name %s is duplicated'             => '字段标识 %s 重复',
    'Title of field %s is invalid'            => '字段 %s 的标题不能为空且不超过50个字符',
    'Type of field %s is invalid'             => '字段 %s 的类型不正确',
    'Maxlength of field %s is invalid'        => '字段 %s 的最大长度必须在1-65535之间',
    '%s of field %s must be numeric'          => '字段 %s 的%s必须是数字',
    'Min of field %s can not be greater than max' => '字段 %s 的最小值不能大于最大值',
    'Options of field %s can not be empty'    => '列表字段 %s 的选项不能为空,请按"值|显示名称"每行一个填写',
    'Options of field %s is invalid'          => '列表字段 %s 的选项不合法,值只能是字母、数字、下划线或中划线,显示名称不超过50个字符',
    'Fields can not be empty'                 => '字段定义不能为空',
    // 数据组校验
    'Field structure can not be changed when the group has data' => '组内已有数据,字段定义不可修改,请先处理组内数据',
    'Please handle the data of the group %s first' => '请先处理数据组 %s 的组内数据',
];
