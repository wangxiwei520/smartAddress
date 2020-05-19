<?php

namespace smartaddress;

class SmartAddress
{
    public  $name;//姓名
    public  $mobile;//电话
    public  $postcode;//邮编
    public  $province;//省
    public  $province_id;//省id
    public  $city;//市
    public  $city_id;//市id
    public  $area;//区
    public  $area_id;//区id
    public  $detail;//详细地址
    public  $address_detail;//地址前段
    private $str;//字符串
    private $library; //地址信息
    private $library_1; //一级地址
    private $library_3; //三级地址
    private $library_parent_id; //三级地址父级id
    private $token; //秘钥


    /*过滤词 */
    private $search =array(
        '退货地址','一','退货须知','地址', '收货地址', '收货人', '收件人', '收货', '邮编', '电话', '：', ':', '；', ';', '，', ',', '。'
    );
    /*替换词 */
    private $replace = array(
        ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', '  ',' ',' ',' '
    );
    /*处理姓名误判*/
    private  $city_name = array(
        '市','省','区','县','镇'
    );
    /*误判长度*/
    private  $len_name =array(
        '市长度长度长度','省长度长度长度','区长度长度长度','县长度长度长度','镇长度长度长度'
    );


    public function __construct($token)
    {
        $address =   cache('address');
        $this->token = $token;
        $this->library = $address[0];//初始化获取地址信息  可保存为配置文件或者换成 从三级城市倒序排列到一级城市
        $this->library_1 = $address[1];//取出一级地址
        $this->library_3 = $address[3];//取出三级地址

    }

    public  function dereferencing($address)
    {
        if ($this->token!=config('token')) {
            return prompt('Illegal operation The IP has been recorded.','',403);
        }
        $this->str = trim($address);

        $this->subdivide();//初步处理
        $this->extractMobile();//提取号码
        $this->extractPostcode();//提取邮编
        $this->extractDetail();//划分地址信息和用户名
        $this->address_detail = $this->detail;
        $this->extractArea();//进一步获取地址详细信息 三级地址
        //如果不存在三级地址则中断排查
        if ($this->area) {
            $this->extractProvince();//取一级二级地址地址
        }else if($this->postcode){
            //如果邮编存在根据邮编排查
            $this->getPostcode();

        }

        $data = array(
            'name'=>$this->name,
            'mobile'=>$this->mobile,
            'postcode'=>$this->postcode,
            'province'=>$this->province,
            'province_id'=>$this->province_id,
            'city'=>$this->city,
            'city_id'=>$this->city_id,
            'area'=>$this->area,
            'area_id'=>$this->area_id,
            'detail'=>str_replace(' ','',$this->detail),

        );
        return  $data;
    }

    //初步处理数据 过滤掉收货地址中的常用说明字符，排除干扰词
    public function subdivide()
    {
        $this->str = str_replace($this->search, $this->replace, $this->str);
        // 连续2个或多个空格替换成一个空格
        $this->str = preg_replace('/ {2,}/', ' ', $this->str);

    }

    //提取电话号码
    public function extractMobile()
    {

        //去除座机号或者手机号码中的短横线 如0826-2616834  138-5214-6894主要针对苹果手机 因为只会同时存在一个 使用所以依次匹配不会冲突
        $this->str = preg_replace('/(\d{4})-(\d{7,8})/', '$1$2', $this->str);//匹配座机
        $this->str = preg_replace('/(\d{3})-(\d{4})-(\d{4})/', '$1$2$3', $this->str);//匹配手机

        // 提取7-11位号码
        preg_match('/\d{7,12}/', $this->str, $match);

        if ($match && $match[0]) {
            $this->mobile = $match[0];
            $this->str = str_replace($match[0], '', $this->str);
        }

    }
    //提取邮编
    public function extractPostcode()
    {
        //提取6位邮编 邮编可从地址库匹配出 或者用于地址库的解析
        preg_match('/\d{6}/', $this->str, $match);
        if ($match && $match[0]) {
            $this->postcode = $match[0];
            $this->str = str_replace($match[0], '', $this->str);
        }

    }

    //划分地址信息和用户名
    public function extractDetail()
    {
        //把2个及其以上的空格合并成一个
        $this->str = trim(preg_replace('/ {2,}/', ' ', $this->str));


        foreach ($this->library_1 as $key=>$value){//查找是否有一级地址
            $area_name = $value['area_name'];

            if (mb_strpos($area_name,'省')) {

                $area_name = mb_substr($area_name,0,mb_strlen($area_name)-1);

            }elseif (mb_strpos($area_name,'自治区')){
                $area_name = mb_substr($area_name,0,mb_strlen($area_name)-3);
            }elseif (mb_strpos($area_name,'特别行政区')){
                $area_name = mb_substr($area_name,0,mb_strlen($area_name)-5);
            }

            if (mb_strpos($this->str,$area_name) || mb_strpos($this->str,$area_name)===0) {//先去短在取长
                if(mb_strpos($this->str,$value['area_name']) || mb_strpos($this->str,$value['area_name'])===0){//如果有全称
                    $area_name = $value['area_name'];
                }
                //起始位置加长度
                $str_num =    mb_strpos($this->str,$value['area_name'])+mb_strlen($area_name);

                $facies =  trim(mb_substr($this->str,0,$str_num));//地址前面部分
                $areadz =  trim(mb_substr($this->str,$str_num));//地址后面部分
                $this->str = $facies.$areadz;


            }
        }




        $this->str = str_replace($this->city_name, $this->len_name, $this->str);

        //按照空格切分 长度长的为地址 短的为姓名 因为地址大概率长于姓名
//        var_dump($this->str);exit;
        $split_arr = explode(' ', $this->str);
        if (count($split_arr) > 1) {
            $this->name = $split_arr[0];

            foreach ($split_arr as $value) {
                if (strlen($value) < strlen($this->name)) {
                    $this->name =$value;
                }
            }
            $this->name = str_replace('长度长度长度','',$this->name);
            $this->str = trim(str_replace($this->name, '', $this->str));
        }
        $this->detail = str_replace('长度长度长度','',$this->str);


    }

    //抓取三级市  PS：核心
    public function extractArea()
    {


        //以三级地址为标准进行匹配 优先找出三级地址关键字 '县','区','旗'
//          var_dump(mb_strstr($this->detail,'区'));exit;
        if (mb_strstr($this->detail,'县')   || mb_strstr($this->detail,'区')) {

            $area_name = null;
            $keyword_pos = 0;
            //优先最低级开始排除
            if (mb_strstr($this->detail, '区')) {

                $keyword_pos = mb_strpos($this->detail, '区');

                //如果三级地址前同时出现二级地址
                if (mb_strstr($this->detail, '市')) {

                    $city_pos = mb_strripos($this->detail, '市');
                    $zone_pos = mb_strripos($this->detail, '区');

                    $area_name = mb_substr($this->detail, $city_pos + 1, $zone_pos - $city_pos);//从二级地址开始截取 截取到三级地址

                } else {

                    //否则默认截取三个
                    $area_name = mb_substr($this->detail, $keyword_pos - 2, 3);
                }
            }


            //如果出现县
            if (mb_strstr($this->detail, '县')) {

                $keyword_pos = mb_strpos($this->detail, '县');
                // 判断县市是同时存在 同时存在 可以简单 比如【湖南省常德市澧县】
                if (mb_strstr($this->detail, '市')) {
                    $city_pos = mb_strripos($this->detail, '市');
                    $zone_pos = mb_strripos($this->detail, '县');
                    $area_name = mb_substr($this->detail, $city_pos + 1, $zone_pos - $city_pos);


                } else {
                    //否则默认三个
                    $area_name = mb_substr($this->detail, $keyword_pos - 2, 3);

                }
            }

            //验证是否是三级地址
            $this->validationCity($area_name,$keyword_pos,1);
            if (empty($this->area)) {

                //如果上述方式为取到三级地址 则终极解决办法
                foreach ($this->library_3 as $key=>$value){
                    //直接拿三级地址匹配详情
                    if (mb_strpos($this->detail,$value['area_name']) ||mb_strpos($this->detail,$value['area_name'])===0) {
                        $area_name = $value['area_name'];
                        $keyword_pos =  mb_strripos($this->detail, $area_name)+(strlen($area_name)-1);
                        $this->validationCity($area_name,$keyword_pos,1);
                        break;
                    }

                }


            }



        }else{
            //特殊三级地区 【县级市】

//            var_dump(mb_strripos($this->detail, '市'));exit;

            if (mb_strripos($this->detail, '市')) {
                //找出'市最后一次出现的位置'
                $keyword_pos = mb_strripos($this->detail, '市');
                // 取三位   目测三级县级市名字都是三个字
                $area_name = mb_substr($this->detail, $keyword_pos - 2, 3);

                //验证是否为三级地区 是则自动设置
                $this->validationCity($area_name,$keyword_pos);


            }



        }

    }

    //抓取一级二级城市
    public function extractProvince()
    {

        if ($this->area) {
            //取二级

            $city_parentid = null; //默认无一级城市id
            foreach ($this->library as  $key=>$value){
                //寻找到二级城市时获取一级城市id

                if ($this->library_parent_id==$value['area_id']) {
                    $city_parentid=$value['area_parent_id'];
                    $this->city = $value['area_name'];
                }

                if (!empty($city_parentid)) {
                    if ($value['area_id'] ==$city_parentid) {
                        $this->province = $value['area_name'];
                        $this->province_id = $value['area_id'];
                    }
                }
            }


        }
    }

    //利用邮编查找城市
    public function getPostcode()
    {


        if ($this->postcode ) {//邮编和待定区域字符串均不为空
            $city_array = array();
            //利用邮编匹配城市
            foreach ($this->library_3 as $key=>$value){

                if ($value['postcode']==$this->postcode) {
                    array_push($city_array,$value['area_name']);
                }

            }


            $row = preg_split('/(?<!^)(?!$)/u', $this->detail );//将地址进行分割
            $count = array();
            //搜索到对应城市
            if (!empty($city_array)) {
                foreach ($city_array as $k=>$value){
                    $count[$k] = 0;
                    foreach ($row as $value1){

                        if (strpos($value,$value1)) {

                            $count[$k]+=1;
                        }

                    }
                }
                //城市最大值下标

                $city_array_key =  array_search(max($count),$count);

                if ($city_array_key>0) {//如果有匹配值

                    $area_name=$city_array[$city_array_key];

                    $keyword_pos = 0;
                    $this->validationCity($area_name,$keyword_pos,1);

                    $this->extractProvince();

                }
            }


        }

    }

    //验证是否为三级城市
    public function validationCity($area_name,$keyword_pos,$status=0)
    {

        $type = false;
        if (!empty($area_name)) {
            $this->address_detail = mb_substr($this->detail, 0,mb_strripos($this->detail, $area_name));
            foreach ($this->library_3 as $key=>$value){
                //特殊处理只需取县级市

                if ($status) {

                    if ($area_name==$value['area_name'] ) {

                        //根据父级id 查找父级城市名称 同时判断是否是同一父级
                        $city =  $this->getName($value['area_parent_id']);

                        $city = mb_substr($city,0,2);
                        //只存在一个地址 或者匹配到父级
                        if ($this->countAddress($area_name)==1 || mb_strripos($this->address_detail, $city)!==false) {
                            $this->area = $area_name;//三级地址
                            $this->library_parent_id = $value['area_parent_id'];//二级地址id
                            $this->area_id = $value['area_id'];//id
                            $this->city_id = $value['area_parent_id'];//id
                            $this->postcode = $value['postcode'];//邮编
                            $this->detail = mb_substr($this->detail, $keyword_pos + 1);//如果有匹配则确定详细地址

                            break;
                        }

                    }
                }else{
                    if (mb_strstr($value['area_name'],'市')) {

                        if ($area_name==$value['area_name']) {
                            //根据父级id 查找父级城市名称 同时判断是否是同一父级
                            $city =  $this->getName($value['area_parent_id']);
                            $city = mb_substr($city,0,2);
                            //只存在一个地址 或者匹配到父级
                            if ($this->countAddress($area_name)==1 || mb_strripos($this->address_detail, $city) !==false ) {
                                $this->area = $area_name;//三级地址
                                $this->library_parent_id = $value['area_parent_id'];//二级地址id
                                $this->area_id = $value['area_id'];//id
                                $this->city_id = $value['area_parent_id'];//id
                                $this->postcode = $value['postcode'];//邮编
                                $this->detail = mb_substr($this->detail, $keyword_pos + 1);//如果有匹配则确定详细地址

                                break;
                            }
                        }
                    }
                }

            }

        }

    }

    /**根据id 获取城市名称
     * @param $id
     */
    public  function getName($id){
         $addressName =    cache('addressName');
         return  $addressName[$id];
    }

    /**判断是否是单一城市
     * @param $key
     */
    public function countAddress($key)
    {
        $addressName =    cache('addressName');
        $count =   array_count_values($addressName);
        if (!empty($count[$key])) {
            return $count[$key];
        }
            return 0;
    }

    public static function aa()
    {

    }
}
