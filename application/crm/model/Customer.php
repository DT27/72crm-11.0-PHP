<?php
// +----------------------------------------------------------------------
// | Description: 客户
// +----------------------------------------------------------------------
// | Author:  Michael_xu | gengxiaoxu@5kcrm.com
// +----------------------------------------------------------------------
namespace app\crm\model;

use app\admin\controller\ApiCommon;
use think\Db;
use app\admin\model\Common;
use app\admin\model\User as UserModel;
use app\admin\model\Record as RecordModel;
use think\Request;
use think\Validate;

class Customer extends Common
{
	/**
     * 为了数据库的整洁，同时又不影响Model和Controller的名称
     * 我们约定每个模块的数据表都加上相同的前缀，比如CRM模块用crm作为数据表前缀
     */
	protected $name = 'crm_customer';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
	protected $autoWriteTimestamp = true;

	protected $type = [
		// 'next_time' => 'timestamp',
	];

	public static $address = [
		'北京', '上海', '天津', '广东', '浙江', '海南', '福建', '湖南', 
		'湖北', '重庆', '辽宁', '吉林', '黑龙江', '河北', '河南', '山东', 
		'陕西', '甘肃', '青海', '新疆', '山西', '四川', '贵州', '安徽', 
		'江西', '江苏', '云南', '内蒙古', '广西', '西藏', '宁夏'
	];

	/**
     * [getDataList 客户list]
     * @author Michael_xu
     * @param     [string]                   $map [查询条件]
     * @param     [number]                   $page     [当前页数]
     * @param     [number]                   $limit    [每页数量]
     * @return    [array]                    [description]
     */		
	public function getDataList($request)
    {
    	$userModel = new \app\admin\model\User();
    	$structureModel = new \app\admin\model\Structure();
    	$fieldModel = new \app\admin\model\Field();
		$search = $request['search'];
    	$user_id = $request['user_id'];
    	$scene_id = (int)$request['scene_id'];
    	$is_excel = $request['is_excel']; //导出
    	$action = $request['action'];
    	$order_field = $request['order_field'];
    	$order_type = $request['order_type'];
    	$is_remind = $request['is_remind'];
    	$getCount = $request['getCount'];
    	$otherMap = $request['otherMap'];
    	//需要过滤的参数
    	$unsetRequest = ['scene_id','search','user_id','is_excel','action','order_field','order_type','is_remind','getCount','type','otherMap'];
    	foreach ($unsetRequest as $v) {
    		unset($request[$v]);
    	}
        $request = $this->fmtRequest( $request );
        $requestMap = $request['map'] ? : [];
		$sceneModel = new \app\admin\model\Scene();
        # getCount是代办事项传来的参数，代办事项不需要使用场景
        $sceneMap = [];
        if (empty($getCount)) {
            if ($scene_id) {
                //自定义场景
                $sceneMap = $sceneModel->getDataById($scene_id, $user_id, 'customer') ? : [];
            } else {
                //默认场景
                $sceneMap = $sceneModel->getDefaultData('crm_customer', $user_id) ? : [];
            }
        }
		$searchMap = [];
		if ($search) {
			//普通筛选
			$searchMap = function($query) use ($search){
			        $query->where('customer.name',array('like','%'.$search.'%'))
			        	->whereOr('customer.mobile',array('like','%'.$search.'%'))
			        	->whereOr('customer.telephone',array('like','%'.$search.'%'));
			};
		}
		$partMap = [];
		//优先级：普通筛选>高级筛选>场景
		if (is_array($sceneMap)) {
			if ($sceneMap['ro_user_id'] && $sceneMap['rw_user_id']) {
				//相关团队查询
				$map = $requestMap;
				$partMap = function($query) use ($sceneMap){
				        $query->where('FIND_IN_SET('.$sceneMap['ro_user_id'].', customer.ro_user_id)')
				        	->whereOr('FIND_IN_SET('.$sceneMap['rw_user_id'].', customer.rw_user_id)');
				};				
			} else {
				$map = $requestMap ? array_merge($sceneMap, $requestMap) : $sceneMap;
			}
		}
		//高级筛选
		$map = where_arr($map, 'crm', 'customer', 'index');
		//公海
		$customerMap = [];
		$authMap = [];
		$poolMap = [];
		$requestData = $this->requestData();
		if ($requestData['a'] == 'pool' || $action == 'pool') {	
			//客户公海条件(没有负责人或已经到期)
        	$poolMap = is_object($sceneMap) ? $sceneMap : $this->getWhereByPool();
		} else {
			$customerMap = ($is_remind == 1) ? $this->getWhereByRemind() : $this->getWhereByCustomer(); //默认条件
			//工作台仪表盘
			if ($requestData['a'] == 'indexlist' && $requestData['c'] == 'index') {
				$customerMap =$this->getWhereByCustomer();
			}
			if (!$partMap) {
				//权限
				$a = 'index';
				if ($is_excel) $a = 'excelExport';
				$auth_user_ids = $userModel->getUserByPer('crm', 'customer', $a);
			    //过滤权限
			    if (isset($map['customer.owner_user_id']) && $map['customer.owner_user_id'][0] != 'like') {
			    	if (!is_array($map['customer.owner_user_id'][1])) {
						$map['customer.owner_user_id'][1] = [$map['customer.owner_user_id'][1]];
					}
					if (in_array($map['customer.owner_user_id'][0], ['neq', 'notin'])) {
						$auth_user_ids = array_diff($auth_user_ids, $map['customer.owner_user_id'][1]) ? : [];	//取差集
					} else {
						$auth_user_ids = array_intersect($map['customer.owner_user_id'][1], $auth_user_ids) ? : [];	//取交集
					}
			        unset($map['customer.owner_user_id']);
					$auth_user_ids = array_merge(array_unique(array_filter($auth_user_ids))) ? : ['-1'];
				    //负责人、相关团队
				    $authMap['customer.owner_user_id'] = array('in',$auth_user_ids);
			    } else {
					$authMapData = [];
			    	$authMapData['auth_user_ids'] = $auth_user_ids;
			    	$authMapData['user_id'] = $user_id;
			    	$authMap = function($query) use ($authMapData){
			    		$query->where(['customer.owner_user_id' => array('in',$authMapData['auth_user_ids'])])
			    			  	->whereOr(function ($query) use ($authMapData) {
			                        $query->where('FIND_IN_SET("'.$authMapData['user_id'].'", customer.ro_user_id)')->where(['customer.owner_user_id' => array('neq','')]);
			                    })
								->whereOr(function ($query) use ($authMapData) {
			                        $query->where('FIND_IN_SET("'.$authMapData['user_id'].'", customer.rw_user_id)')->where(['customer.owner_user_id' => array('neq','')]);
			                    });
				    };			    			    	
			    }
			}			
		}
		$dataCount = db('crm_customer')->alias('customer')
        			->where($map)
        			->where($searchMap)
        			->where($customerMap)
        			->where($authMap)
        			->where($partMap)
        			->where($poolMap)
        			->where($otherMap)
        			->count();
        if ($getCount == 1) {
			$data['dataCount'] = $dataCount ? : 0;
	        return $data;
        }
		//列表展示字段
		$indexField = $fieldModel->getIndexField('crm_customer', $user_id, 1) ? : array('name');
		$userField = $fieldModel->getFieldByFormType('crm_customer', 'user'); //人员类型
		$structureField = $fieldModel->getFieldByFormType('crm_customer', 'structure'); //部门类型
        $datetimeField = $fieldModel->getFieldByFormType('crm_customer', 'datetime'); //日期时间类型
		//排序
		if ($order_type && $order_field) {
			$order = $fieldModel->getOrderByFormtype('crm_customer','customer',$order_field,$order_type);
		} else {
			$order = 'customer.update_time desc';
		}
		//置顶
		// $tops = Db::name('crm_top')->where(['module' => ['eq','customer'],'create_role_id' => ['eq',$user_id],'set_top' => ['eq',1]])->order('top_time asc')->column('module_id');
		// $top_ids = implode(",", $tops);
		// if ($tops) {
		// 	$order_t = DB::raw("field(customer_id, $top_ids) desc");
		// }
		$list = db('crm_customer')->alias('customer')
				->where($map)
				->where($searchMap)
				->where($customerMap)
				->where($authMap)
				->where($partMap)
				->where($poolMap)
				->where($otherMap)
        		->limit($request['offset'], $request['length'])
        		->field('customer.*')
        		->orderRaw($order)
				->select();
        //保护规则
		$configModel = new \app\crm\model\ConfigData();
        $configInfo = $configModel->getData();
        $paramPool = [];
        $paramPool['config'] = $configInfo['config'] ? : 0;
        $paramPool['follow_day'] = $configInfo['follow_day'] ? : 0;
        $paramPool['deal_day'] = $configInfo['deal_day'] ? : 0;
        $paramPool['remind_config'] = $configInfo['remind_config'] ? : 0;

        $readAuthIds = $userModel->getUserByPer('crm', 'customer', 'read');
        $updateAuthIds = $userModel->getUserByPer('crm', 'customer', 'update');
		$deleteAuthIds = $userModel->getUserByPer('crm', 'customer', 'delete');
        if (!empty($list)) {
			$customer_id_list = array_column($list, 'customer_id');
			$business_count = db('crm_business')
				->field([
					'COUNT(*)' => 'count',
					'customer_id'
				])
				->where([
					'customer_id' => ['IN', $customer_id_list]
				])
				->group('customer_id')
				->select();
			$business_count = array_column($business_count, null, 'customer_id');
			$field_list = $fieldModel->getIndexFieldConfig('crm_customer', $user_id);
			$field_list = array_column($field_list, 'field');
			foreach ($list as $k => $v) {
	        	$list[$k]['create_user_id_info'] = isset($v['create_user_id']) ? $userModel->getUserById($v['create_user_id']) : [];
				$list[$k]['owner_user_id_info'] = isset($v['owner_user_id']) ? $userModel->getUserById($v['owner_user_id']) : [];
				$list[$k]['create_user_name'] = !empty($list[$k]['create_user_id_info']['realname']) ? $list[$k]['create_user_id_info']['realname'] : '';
                $list[$k]['owner_user_name'] = !empty($list[$k]['owner_user_id_info']['realname']) ? $list[$k]['owner_user_id_info']['realname'] : '';
				foreach ($userField as $key => $val) {
					if (in_array($val, $field_list)) {
                        $usernameField  = !empty($v[$val]) ? db('admin_user')->whereIn('id', stringToArray($v[$val]))->column('realname') : [];
                        $list[$k][$val] = implode($usernameField, ',');
					}
	        	}
				foreach ($structureField as $key => $val) {
					if (in_array($val, $field_list)) {
                        $structureNameField = !empty($v[$val]) ? db('admin_structure')->whereIn('id', stringToArray($v[$val]))->column('name') : [];
                        $list[$k][$val]     = implode($structureNameField, ',');
					}
				}
                foreach ($datetimeField as $key => $val) {
                    $list[$k][$val] = !empty($v[$val]) ? date('Y-m-d H:i:s', $v[$val]) : null;
                }
				//商机数
				$list[$k]['business_count'] = $business_count[$v['customer_id']]['count'] ?: 0;
	        	//距进入公海天数
	        	$poolData = [];
	        	if ($paramPool['config'] == 1 && $requestData['a'] !== 'pool') {
					$paramPool['update_time'] = $v['update_time'];
					$paramPool['deal_time'] = $v['deal_time'];
					$paramPool['is_lock'] = $v['is_lock'];
					$paramPool['deal_status'] = $v['deal_status'];
					$poolData = $this->getPoolDay($paramPool);
                    $list[$k]['pool_day'] = $poolData ? $poolData['poolDay'] : '';
                    $list[$k]['is_pool'] = $poolData ? $poolData['isPool'] : '';
	        	}
	        	if ($paramPool['remind_config'] == 0) {
                    $list[$k]['pool_day'] = '';
                }

	        	//权限
	        	$roPre = $userModel->rwPre($user_id, $v['ro_user_id'], $v['rw_user_id'], 'read');
	        	$rwPre = $userModel->rwPre($user_id, $v['ro_user_id'], $v['rw_user_id'], 'update');
				$permission = [];
				$is_read = 0;
				$is_update = 0;
				$is_delete = 0;
				if (in_array($v['owner_user_id'],$readAuthIds) || $roPre || $rwPre) $is_read = 1;
				if (in_array($v['owner_user_id'],$updateAuthIds) || $rwPre) $is_update = 1;
				if (in_array($v['owner_user_id'],$deleteAuthIds)) $is_delete = 1;	        
		        $permission['is_read'] = $is_read;
		        $permission['is_update'] = $is_update;
		        $permission['is_delete'] = $is_delete;
		        $list[$k]['permission']	= $permission;

                # 关注
                $starWhere = ['user_id' => $user_id, 'target_id' => $v['customer_id'], 'type' => 'crm_customer'];
                $star = Db::name('crm_star')->where($starWhere)->value('star_id');
                $list[$k]['star'] = !empty($star) ? 1 : 0;
                # 日期
                $list[$k]['create_time'] = !empty($v['create_time']) ? date('Y-m-d H:i:s', $v['create_time']) : null;
                $list[$k]['update_time'] = !empty($v['update_time']) ? date('Y-m-d H:i:s', $v['update_time']) : null;
                $list[$k]['last_time']   = !empty($v['last_time'])   ? date('Y-m-d H:i:s', $v['last_time'])   : null;
	        }     	
        }
        $data = [];
        $data['list'] = $list ? : [];
        $data['dataCount'] = $dataCount ? : 0;
        return $data;
    }

	/**
	 * 创建客户主表信息
	 * @author Michael_xu
	 * @param  
	 * @return                            
	 */	
	public function createData($param)
	{
		$fieldModel = new \app\admin\model\Field();
		$userModel = new \app\admin\model\User();
		$customerConfigModel = new \app\crm\model\CustomerConfig();
		//添加上限检测
		if (!$customerConfigModel->checkData($param['create_user_id'],1)) {
			$this->error = $customerConfigModel->getError();
			return false;
		}
		//地址
		$param['address'] = $param['address'] ? implode(chr(10),$param['address']) : '';
		$param['deal_time'] = time(); //领取、分配时间
		$param['deal_status'] = '未成交';		
		//线索转客户
		if ($param['leads_id']) {
			$leadsData = $param;
			$leadsData['create_user_id'] = $param['create_user_id'];
			$leadsData['owner_user_id'] = $param['owner_user_id'];
			$leadsData['ro_user_id'] = '';
			$leadsData['rw_user_id'] = '';
            $leadsData['detail_address'] = $param['detail_address']	? : '';

			$param = $leadsData;
		} 
		// 自动验证
		$validateArr = $fieldModel->validateField($this->name); //获取自定义字段验证规则
		$validate = new Validate($validateArr['rule'], $validateArr['message']);			
		$result = $validate->check($param);
		if (!$result) {
			$this->error = $validate->getError();
			return false;
		}
        unset($param['customer_id']);

		//处理部门、员工、附件、多选类型字段
		$arrFieldAtt = $fieldModel->getArrayField('crm_customer');
		foreach ($arrFieldAtt as $k=>$v) {
			$param[$v] = arrayToString($param[$v]);
		}
		if ($this->data($param)->allowField(true)->isUpdate(false)->save()) {
			//修改记录
			updateActionLog($param['create_user_id'], 'crm_customer', $this->customer_id, '', '', '创建了客户');			
			$data = [];
			$data['customer_id'] = $this->customer_id;
			$data['name'] = $param['name'];

            # 添加活动记录
            Db::name('crm_activity')->insert([
                'type'             => 2,
                'activity_type'    => 2,
                'activity_type_id' => $data['customer_id'],
                'content'          => $data['name'],
                'create_user_id'   => $param['create_user_id'],
                'update_time'      => time(),
                'create_time'      => time()
            ]);

			return $data;
		} else {
			$this->error = '添加失败';
			return false;
		}			
	}
	
	//根据IDs获取数组
	public function getDataByStr($idstr)
	{
		$idArr = stringToArray($idstr);
		if (!$idArr) {
			return [];
		}
		$list = db('crm_customer')->where(['customer_id' => ['in',$idArr]])->select();
		return $list;
	}
	
	/**
	 * 编辑客户主表信息
	 * @author Michael_xu
	 * @param  
	 * @return                            
	 */
    public function updateDataById($param, $customer_id = '')
    {
        $user_id = $param['user_id'];
        $dataInfo = $this->get($customer_id);
        if (!$dataInfo) {
            $this->error = '数据不存在或已删除';
            return false;
        }
        $id = $param['id']?:$customer_id;
        //数据权限判断
        $userModel = new \app\admin\model\User();
        $auth_user_ids = $userModel->getUserByPer('crm', 'customer', 'update');
        //读写权限
        $rwPre = $userModel->rwPre($user_id, $dataInfo['ro_user_id'], $dataInfo['rw_user_id'], 'update');
        //判断是否客户池数据
        $wherePool = $this->getWhereByPool();
        $resPool = db('crm_customer')->alias('customer')->where(['customer_id' => $id])->where($wherePool)->find();
        if ($resPool || (!in_array($dataInfo['owner_user_id'],$auth_user_ids) && !$rwPre)) {
            $this->error = '无权操作';
            return false;
        }
        
        $param['customer_id'] = $customer_id;
        //过滤不能修改的字段
        $unUpdateField = ['create_user_id','is_deleted','delete_time','user_id'];
        foreach ($unUpdateField as $v) {
            unset($param[$v]);
        }
        $param['deal_status'] = $dataInfo['deal_status'];
        $fieldModel = new \app\admin\model\Field();
        // 自动验证
        $validateArr = $fieldModel->validateField($this->name); //获取自定义字段验证规则
        $validate = new Validate($validateArr['rule'], $validateArr['message']);
        $result = $validate->check($param);
        if (!$result) {
            $this->error = $validate->getError();
            return false;
        }
        //地址
        $param['address'] = $param['address'] ? implode(chr(10),$param['address']) : '';
        if ($param['deal_status'] == '已成交' && $dataInfo->data['deal_status'] == '未成交') {
            $param['deal_time'] = time();
        }
        
        //处理部门、员工、附件、多选类型字段
        $arrFieldAtt = $fieldModel->getArrayField('crm_customer');
        foreach ($arrFieldAtt as $k=>$v) {
            $param[$v] = arrayToString($param[$v]);
        }
        $param['follow'] = '已跟进';
        if ($this->update($param, ['customer_id' => $customer_id], true)) {
            //修改记录
            updateActionLog($user_id, 'crm_customer', $customer_id, $dataInfo->data, $param);
            $data = [];
            $data['customer_id'] = $customer_id;
            return $data;
        } else {
            $this->error = '编辑失败';
            return false;
        }
    }

    /**
     * 客户数据
     *
     * @param string $id
     * @param int $userId
     * @return Common|array|bool|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
   	public function getDataById($id = '', $userId = 0)
   	{  
		$dataInfo = db('crm_customer')->where(['customer_id' => $id])->find();
		if (!$dataInfo) {
			$this->error = '数据不存在或已删除';
			return false;
		}
		$userModel = new \app\admin\model\User();
		$dataInfo['create_user_id_info'] = isset($dataInfo['create_user_id']) ? $userModel->getUserById($dataInfo['create_user_id']) : [];
		$dataInfo['owner_user_id_info'] = isset($dataInfo['owner_user_id']) ? $userModel->getUserById($dataInfo['owner_user_id']) : [];
        $dataInfo['create_user_name'] = !empty($dataInfo['create_user_id_info']['realname']) ? $dataInfo['create_user_id_info']['realname'] : '';
        $dataInfo['owner_user_name'] = !empty($dataInfo['owner_user_id_info']['realname']) ? $dataInfo['owner_user_id_info']['realname'] : '';
		//保护规则
		$configModel = new \app\crm\model\ConfigData();
        $configInfo = $configModel->getData();
        $paramPool = [];
        $paramPool['config'] = $configInfo['config'] ? : 0;
        $paramPool['follow_day'] = $configInfo['follow_day'] ? : 0;
        $paramPool['deal_day'] = $configInfo['deal_day'] ? : 0;
		//是否公海
    	$poolData = [];
    	if ($paramPool['config'] == 1) {
			$paramPool['update_time'] = $dataInfo['update_time'];
			$paramPool['deal_time'] = $dataInfo['deal_time'];
			$paramPool['is_lock'] = $dataInfo['is_lock'];
			$paramPool['deal_status'] = $dataInfo['deal_status'];
			$paramPool['owner_user_id'] = $dataInfo['owner_user_id'];
			$poolData = $this->getPoolDay($paramPool);
    	} else {
    		if (!$dataInfo['owner_user_id']) {
		        $poolData['isPool'] = 1;
		    }
    	}
    	$dataInfo['pool_day'] = $poolData ? $poolData['poolDay'] : '';
    	$dataInfo['is_pool'] = $poolData ? $poolData['isPool'] : '';
    	# 关注
    	$starId = empty($userId) ? 0 : Db::name('crm_star')->where(['user_id' => $userId, 'target_id' => $id, 'type' => 'crm_customer'])->value('star_id');
    	$dataInfo['star'] = !empty($starId) ? 1 : 0;
    	# 首要联系人
        $primaryId = Db::name('crm_contacts')->where(['customer_id' => $id, 'primary' => 1])->value('contacts_id');
        $dataInfo['contacts_id'] = !empty($primaryId) && $this->getContactsAuth($primaryId) ? $primaryId : 0;
        # 处理时间格式
        $fieldModel = new \app\admin\model\Field();
        $datetimeField = $fieldModel->getFieldByFormType('crm_customer', 'datetime'); //日期时间类型
        foreach ($datetimeField as $key => $val) {
            $dataInfo[$val] = !empty($dataInfo[$val]) ? date('Y-m-d H:i:s', $dataInfo[$val]) : null;
        }
        $dataInfo['create_time'] = !empty($dataInfo['create_time']) ? date('Y-m-d H:i:s', $dataInfo['create_time']) : null;
        $dataInfo['update_time'] = !empty($dataInfo['update_time']) ? date('Y-m-d H:i:s', $dataInfo['update_time']) : null;
        $dataInfo['last_time']   = !empty($dataInfo['last_time'])   ? date('Y-m-d H:i:s', $dataInfo['last_time'])   : null;
        return $dataInfo;
   	}

	/**
     * [客户统计]
     * @author Michael_xu
     * @param
     * @return                  
     */		
	public function getStatistics($request)
    {
    	$userModel = new \app\admin\model\User();
    	$request = $this->fmtRequest( $request );
		$map = $request['map'] ? : [];
		unset($map['search']);
		$where = [];
		//时间段
		$start_time = $map['start_time'];
		$end_time = $map['end_time'] ? $map['end_time'] : time();
        if ($start_time && $end_time) {
            $start_date = date('Y-m-d',$start_time);
            $end_date = date('Y-m-d',$end_time);
            $where_time = " BETWEEN {$start_time} AND {$end_time} ";
            $where_date = " BETWEEN '{$start_date}' AND '{$end_date}' ";
        } else {
            $where_time = " > 0 ";
            $where_date = " != '' ";
        }

		//员工IDS
		$map_user_ids = [];
		if (!empty($map['user_id'])) {
			$map_user_ids = array($map['user_id']);
		} elseif (!empty($map['structure_id'])) {
		    $map_user_ids = $userModel->getSubUserByStr($map['structure_id'], 2);
		}

		# 没有传递员工参数并且部门下没员工的情况
		if (empty($map_user_ids)) return [];

		$perUserIds = $userModel->getUserByPer('bi', 'customer', 'read'); //权限范围内userIds
		$userIds = $map_user_ids ? array_intersect($map_user_ids, $perUserIds) : $perUserIds; //数组交集
		$userIds = array_values($userIds);

		$prefix = config('database.prefix');
		$count = count($userIds);
		$sql = '';
		foreach ($userIds as $key => $user_id) {
			$sql .= "
				SELECT
					(SELECT realname FROM {$prefix}admin_user WHERE id = {$user_id}) as realname,
					COUNT(cu.customer_id) AS customer_num,
					SUM(cu.deal_status = '已成交') AS deal_customer_num,
					IFNULL(
						(SELECT SUM(money) FROM {$prefix}crm_contract WHERE
							owner_user_id = {$user_id} 
							AND order_date {$where_date}
							AND check_status = 2
						), 
						0
					) as contract_money,
					IFNULL(
						(SELECT SUM(money) FROM {$prefix}crm_receivables WHERE
							owner_user_id = {$user_id}
							AND return_time {$where_date}
							AND check_status = 2
						), 
						0
					) as receivables_money
				FROM
					{$prefix}crm_customer as cu
				WHERE
					cu.create_time {$where_time}
					AND cu.owner_user_id = {$user_id}
			";
			if ($count > 1 && $key != $count - 1) {
				$sql .= " UNION ALL ";
			}
		}

		if ($sql == '') {
			return [];
		}

        $customerCount         = 0; # 客户总数
        $dealCustomerCount     = 0; # 成交客户总数
        $contractMoneyCount    = 0; # 合同总金额
        $receivablesMoneyCount = 0; # 回款总金额
		$list = queryCache($sql);
		foreach ($list as &$val) {
			$val['deal_customer_num']     = Floor($val['deal_customer_num']);
			$val['contract_money']        = Floor($val['contract_money']);
			$val['receivables_money']     = Floor($val['receivables_money']);
			$val['deal_customer_rate']    = $val['customer_num'] ? round(($val['deal_customer_num'] / $val['customer_num']) * 100, 2) : 0;
			$val['un_receivables_money']  = $val['contract_money'] - $val['receivables_money'] >= 0 ? $val['contract_money'] - $val['receivables_money'] : '0.00';
			$val['deal_receivables_rate'] = $val['contract_money'] ? round(($val['receivables_money'] / $val['contract_money']) * 100, 2) : 0;

            $customerCount         += $val['customer_num'];
            $dealCustomerCount     += $val['deal_customer_num'];
            $contractMoneyCount    += $val['contract_money'];
            $receivablesMoneyCount += $val['receivables_money'];
		}

		return ['list' => $list, 'total' => [
		    'realname'           => '总计',
		    'customer_num'       => $customerCount,
            'deal_customer_num'  => $dealCustomerCount,
            'contract_money'     => $contractMoneyCount,
            'receivables_money'  => $receivablesMoneyCount
        ]];
    }  

	/**
     * [客户数量]
     * @author Michael_xu
     * @param 
     * @return                   
     */		
	public function getDataCount($map)
	{
		//非公海条件
		// $where = $this->getWhereByCustomer();
        $where = [];
		$dataCount = $this->where($map)->fetchSql(false)->count();
        $count = $dataCount ? : 0;
        return $count;		
	}

	/**
     * [客户默认条件]
     * @author Michael_xu
     * @param 
     * @return                   
     */	
    public function getWhereByCustomer()
    {
		$configModel = new \app\crm\model\ConfigData();
		$userModel = new \app\admin\model\User();
        $configInfo = $configModel->getData();
    	$config = $configInfo['config'] ? : 0;
    	$follow_day = $configInfo['follow_day'] ? : 0;
    	$deal_day = $configInfo['deal_day'] ? : 0;
    	//默认条件(没有到期或已锁定)
    	$data['follow_time'] = time()-$follow_day*86400;
    	$data['deal_time'] = time()-$deal_day*86400;
    	if ($config == 1) {
    		if ($follow_day < $deal_day) {
				$whereData = function($query) use ($data){
			        		$query->where(function ($query) use ($data) {
		                        $query->where(['customer.update_time' => array('gt',$data['follow_time']),'customer.deal_time' => array('gt',$data['deal_time'])]);
		                    })
		                    ->whereOr(['customer.deal_status' => '已成交'])
		                    ->whereOr(['customer.is_lock' => 1]);
						};    			
    		} else {
				$whereData = function($query) use ($data){
			        		$query->where(function ($query) use ($data) {
		                        $query->where(['customer.deal_time' => array('gt',$data['deal_time'])]);
		                    })
		                    ->whereOr(['customer.deal_status' => '已成交'])
		                    ->whereOr(['customer.is_lock' => 1]);
						};    			
    		}
    	}
    	return $whereData ? : '';
    }	

	/**
     * [客户公海条件]
     * @author Michael_xu
     * @param 
     * @return                   
     */	
    public function getWhereByPool()
    {
		$configModel = new \app\crm\model\ConfigData();
        $configInfo = $configModel->getData();
    	$config = $configInfo['config'] ? : 0;
		$follow_day = $configInfo['follow_day'] ? : 0;
    	$deal_day = $configInfo['deal_day'] ? : 0;
    	$whereData = [];
    	//启用    	
    	if ($config == 1) {
			//默认公海条件(没有负责人或已经到期)
	    	$data['follow_time'] = time()-$follow_day*86400;
	    	$data['deal_time'] = time()-$deal_day*86400;
	    	$data['deal_status'] = '未成交';
	    	if ($follow_day < $deal_day) {
				$whereData = function($query) use ($data){
				        	$query->where(['customer.owner_user_id'=>0])
					        	->whereOr(function ($query) use ($data) {
									$query->where(function ($query) use ($data) {
				                        $query->where(['customer.update_time' => array('elt',$data['follow_time'])])
											->whereOr(['customer.deal_time' => array('elt',$data['deal_time'])]);
				                    })
				                    ->where(['customer.is_lock' => 0])
				                    ->where(['customer.deal_status' => ['neq','已成交']]);
								});							
							};  		
	    	} else {
				$whereData = function($query) use ($data){
				        	$query->where(['customer.owner_user_id'=>0])
					        	->whereOr(function ($query) use ($data) {
									$query->where(function ($query) use ($data) {
				                        $query->where(['customer.deal_time' => array('elt',$data['deal_time'])]);
				                    })
				                    ->where(['customer.is_lock' => 0])
				                    ->where(['customer.deal_status' => ['neq','已成交']]);
								});							
							};	    		
	    	}
    	} else {
    		$whereData['customer.owner_user_id'] = 0;
    	}
    	return $whereData ? : '';
    }

	/**
     * 客户权限判断(是否客户公海)
     * @author Michael_xu
     * @param type 1 是公海返回false,默认是公海返回true
     * @return
     */       
    public function checkData($customer_id, $type = '')
    {
    	//权限范围
    	$userModel = new \app\admin\model\User();
    	$authIds = $userModel->getUserByPer(); //权限范围的user_id
    	//是否客户公海
    	$map = $this->getWhereByPool();
    	$where['customer_id'] = $customer_id;
    	$customerInfo = db('crm_customer')->alias('customer')->where($where)->where($map)->find();
    	if ($customerInfo && !$type) {
    		return true;
    	} else {
    		$customerInfo = db('crm_customer')->where(['customer_id' => $customer_id])->find();
    		if (in_array($customerInfo['owner_user_id'], $authIds)) {
    			return true;
    		}
    	}
    	$this->error = '没有权限';
    	return false;
    } 

	/**
     * 客户到期天数
     * @author Michael_xu
     * @param 
     * @return
     */     
    public function getPoolDay($param)
    {
    	$poolDay = '';
    	$isPool = 0;
    	$is_lock = $param['is_lock'] ? : 0;
    	$deal_status = $param['deal_status'] ? : '未成交';
    	$update_time = $param['update_time'];
    	if (strtotime($param['update_time'])) {
    		$update_time = strtotime($param['update_time']);
    	}
    	if (!$is_lock && $deal_status !== '已成交') {
    		$follow_time = time()-$param['follow_day']*86400;
	    	$deal_time = time()-$param['deal_day']*86400;
	    	if (($update_time < $follow_time) || ($param['deal_time'] < $deal_time)) {
				$isPool = 1; //是公海
	    	} else {
				$sub_follow_day = ceil(($update_time-$follow_time)/86400);
				$sub_deal_day = ceil(($param['deal_time']-$deal_time)/86400);
	    		$poolDay = ($sub_deal_day > $sub_follow_day) ? $sub_follow_day : $sub_deal_day;
	    		$poolDay = $poolDay ? : 0;
	    		if ($poolDay < 0) {
	    			$isPool = 1; //是公海
	    		}	    		
	    	}
    	} elseif ($is_lock == 1) {
    		$poolDay = ''; //锁定
    	} elseif ($deal_status == '已成交') {
    		$poolDay = '';
    	}
    	if (!$param['owner_user_id']) {
    		$isPool = 1; //是公海
    	}
    	$data = [];
    	$data['poolDay'] = $poolDay;
    	$data['isPool'] = $isPool;
    	return $data;
    }

	/**
     * [待进入客户池条件]
     * @author Michael_xu
     * @param 
     * @return                   
     */	
    public function getWhereByRemind()
    {
		$configModel = new \app\crm\model\ConfigData();
        $configInfo = $configModel->getData();
        $config = $configInfo['config'] ? : 0;
        $follow_day = $configInfo['follow_day'] ? : 0;
        $deal_day = $configInfo['deal_day'] ? : 0;
		$remind_config = $configInfo['remind_config'] ? : 0;
		$remind_day = $configInfo['remind_day'] ? : 0;
        $whereData = [];    
		//启用        
        if ($config == 1 && $remind_config == 1) {
            //默认公海条件(没有负责人或已经到期)
            //通过提前提醒时间,计算查询时间段
            $remind_follow_day = ($follow_day-$remind_day > 0) ? ($follow_day-$remind_day) : $follow_day-1;
            $remind_deal_day = ($deal_day-$remind_day > 0) ? ($deal_day-$remind_day) : $deal_day-1;

            if (($follow_day > 0) && ($deal_day > 0)) {
				$follow_between = array(time()-$follow_day*86400,time()-$remind_follow_day*86400);
                $deal_between = array(time()-$deal_day*86400,time()-$remind_deal_day*86400);
                $data['update_between'] = $follow_between;
                $data['deal_between'] = $deal_between;
				if ($follow_day < $deal_day) {
					$whereData = function($query) use ($data){
					        	$query->where(function ($query) use ($data) {
										$query->where(function ($query) use ($data) {
					                        $query->where(['customer.update_time' => array('between',$data['update_between'])])
					                        ->whereOr(['customer.deal_time' => array('between',$data['deal_between'])]);
					                    })
					                    ->where(['customer.is_lock' => 0])
					                    ->where(['customer.deal_status' => ['neq','已成交']]);
									});							
								};  		
		    	} else {
					$whereData = function($query) use ($data){
					        	$query->where(function ($query) use ($data) {
										$query->where(function ($query) use ($data) {
					                        $query->where(['customer.deal_time' => array('between',$data['deal_between'])]);
					                    })
					                    ->where(['customer.is_lock' => 0])
					                    ->where(['customer.deal_status' => ['neq','已成交']]);
									});							
								};	    		
		    	}
            } else {
                $whereData['customer.customer_id'] = 0;
            }
        } else {
            $whereData['customer.customer_id'] = 0;
        }
        return $whereData ? : '';
    } 

	/**
     * [今日进入客户池条件]
     * @author Michael_xu
     * @param 
     * @return                   
     */	
    public function getWhereByToday()
    {
		$configModel = new \app\crm\model\ConfigData();
        $configInfo = $configModel->getData();
        $config = $configInfo['config'] ? : 0;
        $follow_day = $configInfo['follow_day'] ? : 0;
        $deal_day = $configInfo['deal_day'] ? : 0;

        $whereData = [];
        //启用        
        if ($config == 1) {
            //默认公海条件(没有负责人或已经到期)
            //通过提前提醒时间,计算查询时间段
            if (($follow_day > 0) && ($deal_day > 0)) {
                $follow_between = array(strtotime(date('Y-m-d',time()-$follow_day*86400)),time()-$follow_day*86400);
                $deal_between = array(strtotime(date('Y-m-d',time()-$deal_day*86400)),time()-$deal_day*86400);

                $data['update_time'] = time()-$follow_day*86400;
                $data['deal_time'] = time()-$deal_day*86400;
                $data['update_between'] = $follow_between;
                $data['deal_between'] = $deal_between;

				if ($follow_day < $deal_day) {
					$whereData = function($query) use ($data){
					        	$query->where(['customer.owner_user_id'=>0])
						        	->whereOr(function ($query) use ($data) {
										$query->where(function ($query) use ($data) {
					                        $query->where(['customer.update_time' => array('between',$data['update_between'])])
												->whereOr(['customer.deal_time' => array('between',$data['deal_between'])]);
					                    })
					                    ->where(['customer.is_lock' => 0])
					                    ->where(['customer.deal_status' => ['neq','已成交']]);
									});							
								};  		
		    	} else {
					$whereData = function($query) use ($data){
					        	$query->where(['customer.owner_user_id'=>0])
						        	->whereOr(function ($query) use ($data) {
										$query->where(function ($query) use ($data) {
					                        $query->where(['customer.deal_time' => array('between',$data['deal_between'])]);
					                    })
					                    ->where(['customer.is_lock' => 0])
					                    ->where(['customer.deal_status' => ['neq','已成交']]);
									});							
								};	    		
		    	}        
            } else {
                $whereData['customer.customer_id'] = 0;
            }
        } else {
        	$whereData['customer.owner_user_id'] = 0;
        	$whereData['customer.update_time'] = array('between',array(strtotime(date('Y-m-d',time())),time()));
        }
        return $whereData ? : '';
    }    

	/**
     * [客户拥有、锁定数]
     * @author Michael_xu
     * @param is_deal 1包含成交客户
     * @param types 1拥有客户上限2锁定客户上限 
     * @return                   
     */	
    public function getCountByHave($user_id, $is_deal = 0,$types = 1)
    {
		$where = [];
    	$where['owner_user_id'] = $user_id;    	
    	//公海逻辑
		$configModel = new \app\crm\model\ConfigData();
		$userModel = new \app\admin\model\User();
        $configInfo = $configModel->getData();
    	$config = $configInfo['config'] ? : 0;
    	$follow_day = $configInfo['follow_day'] ? : 0;
    	$deal_day = $configInfo['deal_day'] ? : 0;
    	//默认条件(没有到期或已锁定)
    	$data['follow_time'] = time()-$follow_day*86400;
    	$data['deal_time'] = time()-$deal_day*86400;
    	$whereData = '';
    	//公海开启
    	if ($config == 1) {
			switch ($types) {
				case '1' : 
					if ($is_deal !== 1) {
						//不包含成交客户
						$where['deal_status'] = ['neq','已成交'];
						if ($follow_day < $deal_day) {
							$whereData = function($query) use ($data){
						        		$query->where(function ($query) use ($data) {
					                        $query->where(['update_time' => array('gt',$data['follow_time']),'deal_time' => array('gt',$data['deal_time'])]);
					                    });
									};    			
			    		} else {
							$whereData = function($query) use ($data){
						        		$query->where(function ($query) use ($data) {
					                        $query->where(['deal_time' => array('gt',$data['deal_time'])]);
					                    });
									};    			
			    		}						
					} else {
						if ($follow_day < $deal_day) {
							$whereData = function($query) use ($data){
						        		$query->where(function ($query) use ($data) {
					                        $query->where(['update_time' => array('gt',$data['follow_time']),'deal_time' => array('gt',$data['deal_time'])]);
					                    })
					                    ->whereOr(['deal_status' => ['eq','已成交']]);
									};    			
			    		} else {
							$whereData = function($query) use ($data){
						        		$query->where(function ($query) use ($data) {
					                        $query->where(['deal_time' => array('gt',$data['deal_time'])]);
					                    })
					                    ->whereOr(['deal_status' => ['eq','已成交']]);
									};    			
			    		}						
					}
		    		break;    						
				case '2' : 
					$where['is_lock'] = ['eq',1]; 
					//默认不包含成交客户
					$where['deal_status'] = ['neq','已成交'];
		    		break;    					
			}
    	} else {
			//公海未开启
    		if ($is_deal !== 1) {
				//不包含成交客户
				$where['deal_status'] = ['neq','已成交'];					
    		} 
			switch ($types) {
				case '2' : 
					//锁定，默认不包含成交客户
					$where['deal_status'] = ['neq','已成交'];
					$where['is_lock'] = 1; 
					break;
			}
    	}
    	$count = $this->where($where)->where($whereData)->count();
    	return $count ? : 0;
    }

	/**
     * [客户成交新增数量]
     * @author Michael_xu
     * @param 
     * @return                   
     */		
	public function getAddDealSql($map)
	{
		$prefix = config('database.prefix');
		$sql = "SELECT
					'{$map['type']}' AS type,
					'{$map['start_time']}' AS start_time,
					'{$map['end_time']}' AS end_time,
					IFNULL(
						(
							SELECT
								count(customer_id)
							FROM
								{$prefix}crm_customer
							WHERE
								create_time BETWEEN {$map['start_time']} AND {$map['end_time']}
							AND owner_user_id IN ({$map['create_user_id']})
						),
						0
					) AS customer_num,
					IFNULL(
						count(customer_id),
						0
					) AS deal_customer_num
				FROM
					{$prefix}crm_customer
				WHERE
					create_time BETWEEN {$map['start_time']} AND {$map['end_time']}
					AND deal_status = '{$map['deal_status']}' 
					AND owner_user_id IN ({$map['create_user_id']})";
		return $sql;	
	}

	/**
     * [客户统计sql]
     * @author Michael_xu
     * @param 
     * @return                   
     */		
	public function getAddressCountBySql($map)
	{
		$prefix = config('database.prefix');
		$sql = "SELECT
					'{$map['address']}' AS address,
					IFNULL(
						(
							SELECT
								count(customer_id)
							FROM
								{$prefix}crm_customer
							WHERE
								address LIKE '%{$map['address']}%'
							AND owner_user_id IN ({$map['owner_user_id']})
						),
						0
					) AS allCustomer,
					IFNULL(
						count(customer_id),
						0
					) AS dealCustomer
				FROM
					{$prefix}crm_customer
				WHERE
					address LIKE '%{$map['address']}%'
				AND deal_status = '{$map['deal_status']}' 
				AND owner_user_id IN ({$map['owner_user_id']})";
		$list = $this->query($sql) ? : [];
		return $list;	
	}

    /**
     * 获取附近的客户
     *
     * @param $param
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getNearbyList($param)
    {
        $apiCommon = new ApiCommon();
        $userModel = new \app\admin\model\User();

        $poolStatus = checkPerByAction('crm', 'customer', 'pool');

        # 客户
        $customerWhere = [];
        if ((!empty($param['type']) && $param['type'] == 2) || !$poolStatus) {
            $customerWhere = $this->getWhereByCustomer();
        }

        # 公海
        $poolWhere = [];
        if (!empty($param['type']) && $param['type'] == 9 && $poolStatus) {
            $poolWhere = $this->getWhereByPool();
        }

        if (!empty($param['type']) && $param['type'] == 9 && !$poolStatus) {
            return [];
        }

        # 基本权限
//        $auth_user_ids = $userModel->getUserByPer('crm', 'customer', 'index');
//        $authMapData['auth_user_ids'] = $auth_user_ids;
//        $authMapData['user_id'] = $apiCommon->userInfo['id'];
//        $authMap = function($query) use ($authMapData){
//            $query->where(['customer.owner_user_id' => array('in',$authMapData['auth_user_ids'])])
//                ->whereOr(function ($query) use ($authMapData) {
//                    $query->where('FIND_IN_SET("'.$authMapData['user_id'].'", customer.ro_user_id)')->where(['customer.owner_user_id' => array('neq','')]);
//                })
//                ->whereOr(function ($query) use ($authMapData) {
//                    $query->where('FIND_IN_SET("'.$authMapData['user_id'].'", customer.rw_user_id)')->where(['customer.owner_user_id' => array('neq','')]);
//                });
//        };

        # 附近
        $lngLatRange = $this->getLngLatRange($param['lng'], $param['lat'], $param['distance']);
        $lngLatWhere = function ($query) use ($lngLatRange) {
            $query->where(['lng' => ['egt', $lngLatRange['minLng']]]);
            $query->where(['lng' => ['elt', $lngLatRange['maxLng']]]);
            $query->where(['lat' => ['egt', $lngLatRange['minLat']]]);
            $query->where(['lat' => ['elt', $lngLatRange['maxLat']]]);
        };

        # 经纬度值计算出错
        if (empty($lngLatRange['minLng']) || empty($lngLatRange['maxLng']) || empty($lngLatRange['minLat']) || empty($lngLatRange['maxLat'])) {
            return ['list' => [], 'count' => 0];
        }

        $count = db('crm_customer')->alias('customer')->where($customerWhere)->where($poolWhere)->where($lngLatWhere)->count();

        $list = db('crm_customer')->alias('customer')
            ->where($customerWhere)
            ->where($poolWhere)
            ->where($lngLatWhere)
//            ->where($authMap)
            ->field(['customer_id', 'name', 'address', 'detail_address', 'owner_user_id', 'lat', 'lng'])
            ->order('update_time', 'desc')
            ->select();

        # 组装数据
        foreach ($list as $key => $value) {
            # todo 暂时将查询写在循环中
            $ownerUserInfo = !empty($value['owner_user_id'])    ? $userModel->getUserById($value['owner_user_id']) : [];
            $ownerUserName = !empty($ownerUserInfo['realname']) ? $ownerUserInfo['realname'] : '';
            $list[$key]['owner_user_name'] = $ownerUserName;
            $list[$key]['distance'] = $this->getLngLatDistance($param['lng'], $param['lat'], $value['lng'], $value['lat'], 1, 0);
        }

        return ['list' => $list, 'count' => $count];
    }

    /**
     * 计算两点地理坐标之间的距离
     *
     * @param number $longitude1 起点经度
     * @param number $latitude1 起点纬度
     * @param number $longitude2 终点经度
     * @param number $latitude2 终点纬度
     * @param int $unit 单位：1米；2公里
     * @param int $decimal 精度：保留小数位数
     * @return float
     */
    private function getLngLatDistance($longitude1, $latitude1, $longitude2, $latitude2, $unit=2, $decimal=2)
    {
        $EARTH_RADIUS = 6370.996;  // 地球半径系数
        $PI           = 3.1415926;

        $radLat1 = $latitude1 * $PI / 180.0;
        $radLat2 = $latitude2 * $PI / 180.0;

        $radLng1 = $longitude1 * $PI / 180.0;
        $radLng2 = $longitude2 * $PI /180.0;

        $a = $radLat1 - $radLat2;
        $b = $radLng1 - $radLng2;

        $distance = 2 * asin(sqrt(pow(sin($a / 2), 2) + cos($radLat1) * cos($radLat2) * pow(sin($b / 2), 2)));
        $distance = $distance * $EARTH_RADIUS * 1000;

        if( $unit==2 ) $distance = $distance / 1000;

        return round($distance, $decimal);
    }

    /**
     * 获取经纬度范围值
     *
     * @param $myLng
     * @param $myLat
     * @param $distance
     * @return array
     */
    private function getLngLatRange($myLng, $myLat, $distance)
    {
        $pi = pi();

        # 公里
        $distance = $distance / 1000;

        # 计算纬度区间
        $latRange = 180 / $pi * $distance / 6372.797;
        # 计算进度区间
        $lngRang  = $latRange / cos($myLat * $pi / 180);

        $maxLng = $myLng + $lngRang;  # 最大经度
        $minLng = $myLng - $lngRang;  # 最小经度
        $maxLat = $myLat + $latRange; # 最大纬度
        $minLat = $myLat - $latRange; # 最小纬度

        return ['maxLng' => $maxLng, 'minLng' => $minLng, 'maxLat' => $maxLat, 'minLat' => $minLat];
    }

    /**
     * 获取系统信息
     *
     * @param $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getSystemInfo($id)
    {
        # 客户
        $field = ['obtain_time', 'deal_status', 'create_time', 'update_time', 'create_time'];
        $customer = Db::name('crm_customer')->field($field)->where('customer_id', $id)->find();
        # 创建人
        $realname = Db::name('admin_user')->where('id', $customer['create_user_id'])->value('realname');
        # 最后跟进时间
        $follow = Db::name('crm_activity')->field(['content', 'update_time'])->where(['type' => 1, 'activity_type' => 2, 'activity_type_id' => $id])->order('activity_id', 'desc')->find();

        return [
            'obtain_time'      => !empty($customer['obtain_time']) ? date('Y-m-d H:i:s', $customer['obtain_time']) : '',
            'follow_record'    => !empty($follow['content']) ? $follow['content'] : '',
            'create_user_name' => $realname,
            'create_time'      => date('Y-m-d H:i:s', $customer['create_time']),
            'update_time'      => date('Y-m-d H:i:s', $customer['update_time']),
            'follow_time'      => !empty($follow['update_time']) ? date('Y-m-d H:i:s', $follow['update_time']) : '',
            'deal_status'      => $customer['deal_status']
        ];
    }

    /**
     * 判断联系人详情权限 todo 商机模块也在用，以后抽成一个公共的方法
     *
     * @param $contactsId
     * @return bool
     */
    private function getContactsAuth($contactsId)
    {
        $ownerUserId = db('crm_contacts')->where('contacts_id', $contactsId)->value('owner_user_id');

        $authUserIds = (new \app\admin\model\User())->getUserByPer('crm', 'contacts', 'read');

        return in_array($ownerUserId, $authUserIds);
    }
}