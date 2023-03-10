<?php
abstract class DB{
    public static $_conn = null;
    static function getInstance($host, $username, $password, $db)
    {
        if (self::$_conn === null) self::$_conn = new MysqliDb(array('host' => $host, 'username' => $username, 'password' => $password, 'db' => $db, 'charset' => 'utf8mb4'));
    }
    static function rawQuery($sql)
    {
        return self::$_conn->rawQuery($sql);
    }
    static function insertLog($action, $value = "")
    {
        $value = json_encode($value);
        if(strlen( $value) > 60000)
            $value = substr($value, 0,60000) . '...';
        $data = array("action" => $action, 'user' => (isset($GLOBALS['currentUser'])) ? stdClassToArray($GLOBALS['currentUser']) : "",  "header" => getallheaders(), "server" => $_SERVER, "parameter" => getParameter($_POST, $_GET), 'data' => $value);
        unset($data['ID']);
        $id = self::$_conn->insert('Log', convertParametersToString($data, Log::getFieldsWithType()));
        if ($id == false) throw new Exception(self::$_conn->getLastError());
    }
    static function getRawJoin($class, $cols = null) //TO BE Deprecated
    {
        self::$_conn->where($class::getSelfName() . "." .'isDeleted', 0);
        $result = self::$_conn->get($class::getSelfName(), null, $cols);
        if(method_exists($class, "permissionGetHandling"))
            $result = $class::permissionGetHandling($result);
        // self::insertLog("GET", $result);
        return $result;
    }

    static function getRaw($class, $options = array())
    {
        self::$_conn->where($class::getSelfName() . "." .'isDeleted', 0);
        $result = self::$_conn->get($class::getSelfName(), null, null);
        if(method_exists($class, "permissionGetHandling") && !isFullRight($options))
            $result = $class::permissionGetHandling($result);
        // self::insertLog("GET", $result);
        return $result;
    }
    static function getAll($class, $options = null){ // TO Be Deprecated
        $modelList = rawDataListTModelList(self::getRaw($class), $class,  $options);
        return $modelList;
    }

    static function getAll_new($class, $options = null){ 
        return getAllApi($options, $class);
    }

    static function getAllMap($class, $options = null){
        $modelList = rawDataListTModelMap(self::getRaw($class), $class,  $options);
        return $modelList;
    }

    static function getCount($class, $whereConditionList)
    {
        self::addWhereConditionList($whereConditionList);
        return sizeof(self::getRaw($class::getSelfName()));
    }

    static function getByID($class, $ID, $options = null)
    {
        try{
            self::$_conn->where("ID", $ID);
            $result = self::getRaw($class::getSelfName(), $options);
            return (sizeof($result) > 0) ? new $class($result[0], $options) : null;
        }catch(Exception $e){
            return null;
        }
    }

    static function getByWhereCondition($class, $whereConditionList,  $options = null)
    {
        self::addWhereConditionList($whereConditionList);
        $modelList = rawDataListTModelList(self::getRaw($class), $class, $options);
        return $modelList;
    }

    static function getByColumn($class, $column, $value,  $options = null)
    {
        $options = isset($options) ? $options : array();
        self::$_conn->where($column, $value);
        $modelList = rawDataListTModelList(self::getRaw($class), $class, $options);
        return $modelList;
    }

    static function deleteByWhereCondition($class, $whereConditionList)
    {
        self::addWhereConditionList($whereConditionList);
        self::$_conn->delete($class::getSelfName());
    }

    static function deleteRealByWhereCondition($class, $whereConditionList)
    {
        self::addWhereConditionList($whereConditionList);
        self::$_conn->delete($class::getSelfName());
    }
    private static function updateRaw($parameters, $class){
        unset($parameters['createdDate']);
        unset($parameters['modifiedDate']);
        // unset($parameters['isDeleted']);
        if(method_exists($class, "permissionUpdateHandling") && !$class::permissionUpdateHandling($parameters, self::getByID($class, $parameters["ID"])))
            throw new Exception("Role Permission Denied");
        $parameters = (array) $parameters;
        self::$_conn->where("ID", $parameters["ID"]);
        $now = new DateTime();
        $parameters["modifiedDate"] = $now->format('Y-m-d H:i:s');
        $result = self::$_conn->update($class::getSelfName(), convertParametersToString($parameters, $class::getFieldsWithType()));
        if ($result == false) throw new Exception(self::$_conn->getLastError());
        self::insertLog("UPDATE", stdClassToArray(self::getByID($class::getSelfName(), $parameters["ID"], BaseModel::SYSTEM)));
    } 
    static function update($parameters, $class)
    {
        $parameters = filterParameterByClass($parameters, $class);
        self::updateRaw($parameters, $class);
    }
    static function delete($ID, $class){
        self::updateRaw(array("ID"=>$ID, "isDeleted"=>1),$class);
    }

    static function realDelete($ID, $class){
        self::$_conn->where('ID', $ID);
        if(!self::$_conn->delete($class::getSelfName()))
            throw new Exception("Delete Error");
    }

    static function insert($parameters, $class){
        $parameters = filterParameterByClass($parameters, $class);
        return self::insertRaw($parameters, $class);
    }
    static function isWhereConditionExisted($class, $whereConditionList){
        return sizeof(DB::getByWhereCondition($class, $whereConditionList)) > 0;
    }

    private static function insertRaw($parameters, $class){
        unset($parameters['ID']);
        unset($parameters['createdDate']);
        unset($parameters['modifiedDate']);
        unset($parameters['isDeleted']);
        if(method_exists($class, "permissionInsertHandling") && !$class::permissionInsertHandling($parameters))
            throw new Exception("Role Permission Denied");
        $typeList =  $class::getFieldsWithType();
        $id = self::$_conn->insert($class::getSelfName(), convertParametersToString(addDefaultValue($parameters, $typeList), $typeList));
        if ($id == false) throw new Exception(self::$_conn->getLastError());
        self::insertLog("INSERT", $parameters);
        return $id;
    }
    static function join($db, $dbObjectList, $whereConditionList = array()){
        self::addWhereConditionList($whereConditionList);
        $field_query = "";
        foreach ($dbObjectList as $dbObject) {
            $field_query .= ", " . fieldQueryForSelect($dbObject["db"]::getSelfName(), $dbObject["mode"] || BaseModel::PUBLIC);
            self::$_conn->join($dbObject["db"]::getSelfName() . " " . $dbObject["db"]::getSelfName(), $dbObject["joinQuery"], "LEFT");
        }
        return parseValue(self::getRawJoin($db::getSelfName(), $db::getSelfName() . ".* " .  $field_query));
    }

    static function startTransaction()
    {
        self::$_conn->startTransaction();
    }

    static function rollback()
    {
        self::$_conn->rollback();
    }

    static function commit()
    {
        self::$_conn->commit();
    }

    private static function addWhereConditionList($whereConditionList){
        foreach ($whereConditionList as $key => $value) {
            if($value === null){
                self::$_conn->where($key, NULL, '<=>');
            }else{
                self::$_conn->where($key, $value);
            }
        }
    }
}

function fieldQueryForSelect($class, $mode = BaseModel::PUBLIC)
{
    $sql = "";
    $fields = filter($class::getFields(), function($field){
        return ($field["type"] !== BaseTypeEnum::TO_MULTI && $field["type"] !== BaseTypeEnum::TO_SINGLE && $field["type"] !== BaseTypeEnum::ARRAY_OF_ID && $field["type"] !== BaseTypeEnum::COMPUTED);
    });
    foreach ($fields as $value) {
        $sql .=  $class::getSelfName() . "." . $value["key"] . " as '" . $class::getSelfName() . "." . $value["key"] . "', ";
    }
    return substr_replace($sql, " ", -2);
}

function addDefaultValue($parameters, $fieldTypeList){
    foreach($fieldTypeList as $field){
        if(!array_key_exists($field["key"],$parameters) || $parameters[$field["key"]] === null){
            switch($field["type"]){
                case BaseTypeEnum::ARRAY:
                    $parameters[$field["key"]] = "[]";
                break;
                case BaseTypeEnum::OBJECT:
                    $parameters[$field["key"]] = "{}";
                break;
            }
        }
    }
    return $parameters;
}

function convertParametersToString($parameters, $typeList)
{
    $result = array();
    foreach ($parameters as $key => $value) {
        if (is_array($value) && find($typeList, function($data)use($key){return $data["key"] === $key;})["type"] === BaseTypeEnum::INT_ARRAY){
            $arrayValue = map($value, function($data){return intval($data);});
            sort($arrayValue);
            $result[$key] = json_encode($arrayValue);
        }
        else if (is_array($value))
            $result[$key] = json_encode($value);
        else
            $result[$key] = $value;
    }
    return $result;
}


function rawDataListTModelMap($rawDataList, $class, $options)
{
    $modelMap = array();
    foreach ($rawDataList as $data) {
        $modelMap[$data["ID"]] = new $class($data, $options);
    }
    return $modelMap;
}

function rawDataListTModelList($rawDataList, $class, $options)
{
    $modelList = array();
    foreach ($rawDataList as $data) {
        array_push($modelList,  new $class($data, $options));
    }
    return $modelList;
}

function isFullRight($options){
    return (isset($options["fullRight"]) && $options["fullRight"] == true);
}