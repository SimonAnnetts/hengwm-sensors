#!/usr/bin/php
<?php

// if (posix_getuid() !== 0) {
//     die("I must be run as user 'root'!\n");
// }

$lon="3.541975W";
$lat="52.301761N";


function usage() {
    return "
hengwn-sensors.sh.php OPTIONS

-h          This Help Text
-d          Set i2c device e.g. i2c-2 (defaults to 12c-2 if not set)
-p          Probe for any new sensors
-r          Read all of the sensors and process the data
-g          Graph the data that's been collected
-D          Delay before graphing to ensure the sensors have all been read
-v          Be verbose and show the data being read
";
}

function error($error) {
    print "$error\n";
    exit(1);
}

function parse_args(&$argc,&$argv) {
    $argv[]="";
    $argv[]="";
    $args=array();
    //build a hashed array of all the arguments
    $i=1; $ov=0;
    while ($i<$argc) {
            if (substr($argv[$i],0,2)=="--") $a=substr($argv[$i++],2);
            elseif (substr($argv[$i],0,1)=="-") $a=substr($argv[$i++],1);
            else $a=$ov++;
            if (strpos($a,"=") >0) {
                    $tmp=explode("=",$a);
                    $args[$tmp[0]]=$tmp[1];
            } else {
                    if (substr($argv[$i],0,1)=="-" or $i==$argc) $v=1;
                    else $v=$argv[$i++];
                    $args[$a]=$v;
            }
    }
    return $args;
}

function get_next_colour($group_id) {
    $colours=array('#e41a1c','#377eb8','#4daf4a','#984ea3','#ff7f00','#ffff33','#a65628','#f781bf');
    $s=get_sensor_definitions_by_group();
    foreach($colours as $c) {
        $found=false;
        if(isset($s[$group_id])) {
            foreach($s[$group_id] as $g=>$v) {
                if($v['colour']==$c) $found=true;
            }
        }
        if(!$found) return $c;
    }
    return '#000000';
}

function read_sensor_list() {
    global $sensor_list,$dir;
    $file=$dir.'/sensor-list.txt';
    if(!file_exists($file)) {
        file_put_contents($file,"# This is the sensors list file.
# Each line must be in the format:
# sensor_id,sensor_name,group_id,line_colour\n\n");
    }
    $sensor_list=@file($file);
}

function get_sensor_definitions_by_id() {
    global $sensor_list;
    $sensors=array();
    foreach($sensor_list as $s) {
        if(preg_match('/^([0-9a-f]{2}\-[0-9a-f]{12}),(.*?),(.*?),(.*?)$/',trim($s),$m)) {
            $sensors[$m[1]]=array('id'=>$m[1],'name'=>trim($m[2]),'group'=>trim($m[3]),'colour'=>trim($m[4]));
        }
    }
    return $sensors;
}

function get_sensor_definitions_by_group() {
    global $sensor_list;
    $sensors=array();
    foreach($sensor_list as $s) {
        if(preg_match('/^([0-9a-f]{2}\-[0-9a-f]{12}),(.*?),(.*?),(.*?)$/',trim($s),$m)) {
            $sensors[trim($m[3])][]=array('id'=>$m[1],'name'=>trim($m[2]),'group'=>trim($m[3]),'colour'=>trim($m[4]));
        }
    }
    return $sensors;
}

function add_sensor_to_list($id, $new_name) {
    global $sensor_list;
    $s=get_sensor_definitions_by_id();
    if(!isset($s[$id])) {
        $group_id='ungrouped';
        $colour=get_next_colour($group_id);
        $sensor_list[]="# New Sensor:\n";
        $sensor_list[]=sprintf("%s,%s,%s,%s\n",$id,trim($new_name),$group_id,$colour);
        write_sensor_list();
        return true;
    }
    return false;
}

function write_sensor_list() {
    global $sensor_list,$dir;
    $file=$dir.'/sensor-list.txt';
    if($fp=@fopen($file,'wb')) {
        foreach($sensor_list as $s) fwrite($fp,$s);
        fclose($fp);
    }
}

function read_group_list() {
    global $group_list,$dir;
    $file=$dir.'/group-list.txt';
    if(!file_exists($file)) {
        file_put_contents($file,"# This is a list of graph groups - 
# sensors belonging to a particular group will be added to the group's graph
# Format:
# group_id,group_name,min,max\n");
    }
    $group_list=@file($file);
}

function get_group_definitions_by_id() {
    global $group_list;
    $groups=array();
    foreach($group_list as $g) {
        if(preg_match('/^(.*?),(.*?),([0-9\-\.]+),([0-9\-\.]+)$/',trim($g),$m)) {
            $groups[trim($m[1])]=array('id'=>trim($m[1]),'name'=>trim($m[2]),'min'=>(float)trim($m[3]),'max'=>(float)trim($m[4]));
        }
    }
    return $groups;
}

function create_rrd_file($id) {
    global $dir;
    $file=$dir.'/data/'.$id.'.rrd';
    
    // useful tool here: https://eccentric.one/misc/rrdcalc.html
    $cmd="
rrdtool create ${file} --step 60 \
'DS:data:GAUGE:300:-30:100' \
'RRA:AVERAGE:0.5:1:1500' \
'RRA:AVERAGE:0.5:5:2028' \
'RRA:AVERAGE:0.5:30:4464' \
'RRA:AVERAGE:0.5:60:43848'
";
    $r=trim(`${cmd} 2>&1`);
    if($r=='') return true; else return $r;
}

function update_rrd_data($id,$value) {
    global $dir,$verbose;
    $file=$dir.'/data/'.$id.'.rrd';
    if(!file_exists($file)) {
        $r=create_rrd_file($id);
        if(true!==$r) return $r;
    }
    $cmd="rrdtool update ${file} N:${value}";
    $r=trim(`${cmd} 2>&1`);
    if($verbose) printf("Update %s.rrd Value: %s Return: %s\n",$id,$value,$r);
    if($r=='') return true; else return $r;    
}

function graph_rrd_data($group_id,$graph_data) {
    global $dir,$sunr,$suns,$dusk,$dawn,$sun,$twilight,$verbose;
    $date=date('Y-m-d H:i:s');
    $images=array();
    $periods=array(
        'One Day'=>array('name'=>'day','start'=>'end-1days'),
        'One Week'=>array('name'=>'week','start'=>'end-7days'),
        'One Quarter'=>array('name'=>'quarter','start'=>'end-3months'),
        'One Year'=>array('name'=>'year','start'=>'end-1years'),
        'Five Years'=>array('name'=>'5year','start'=>'end-5years')  
    );
    $groups=get_group_definitions_by_id();

    // useful tool here: http://rrdwizard.appspot.com/rrdgraph.php

    foreach($periods as $period=>$period_data) {
        $group=$groups[$group_id]['name'];
        $min=$groups[$group_id]['min'];
        $max=$groups[$group_id]['max'];
        $period_start=$period_data['start'];
        $period_name=$period_data['name'];
        $cmd="
rrdtool graph '${dir}/www/img/${group_id}-${period_name}.png' \
--imginfo '<IMG SRC=\"img/%s\" WIDTH=\"%lu\" HEIGHT=\"%lu\" TITLE=\"${group} Temperatures (${period}) - Last Reading: ${date}\">' \
--title '<b>${group} Temperatures (${period})</b> - Last Reading: ${date}' \
--vertical-label 'Degrees C' \
--width '600' \
--height '250' \
--upper-limit ${max} \
--lower-limit ${min} \
--start ${period_start} \
--pango-markup \
--color 'CANVAS#FFFFf0' \
--slope-mode \
--watermark 'Hengwm' \
--font 'DEFAULT:9:DejaVu Sans Mono' \
";
        $g=1;
        // print_r($graph_data);
        foreach($graph_data as $gid=>$data) {
            $id=$data['id'];
            $vname=str_replace('-','_',$data['id']); //DEF vnames: Variable names (vname) must be made up strings of the following characters A-Z, a-z, 0-9, _, - and a maximum length of 255 characters.
            $name=str_pad($data['name'],16);
            $colour=$data['colour'];
            $file=$dir.'/data/'.$id.'.rrd';
            $cmd.="DEF:${vname}=${file}:data:AVERAGE \ \n";
            if($g==1) {
                if($period_name=='day' or $period_name=='week') {
                    $cmd.="CDEF:nightplus=LTIME,86400,%,${sunr},LT,INF,LTIME,86400,%,${suns},GT,INF,UNKN,${vname},*,IF,IF \ \n";
                    $cmd.="CDEF:nightminus=LTIME,86400,%,${sunr},LT,NEGINF,LTIME,86400,%,${suns},GT,NEGINF,UNKN,${vname},*,IF,IF \ \n";
                    $cmd.="AREA:nightplus#F0F0F8 \ \n";
                    $cmd.="AREA:nightminus#F0F0F8 \ \n";
                    $cmd.="CDEF:dusktilldawn=LTIME,86400,%,${dawn},LT,INF,LTIME,86400,%,${dusk},GT,INF,UNKN,${vname},*,IF,IF \ \n";
                    $cmd.="CDEF:dawntilldusk=LTIME,86400,%,${dawn},LT,NEGINF,LTIME,86400,%,${dusk},GT,NEGINF,UNKN,${vname},*,IF,IF \ \n";
                    $cmd.="AREA:dusktilldawn#F8F8FF \ \n";
                    $cmd.="AREA:dawntilldusk#F8F8FF \ \n";
                }
                $cmd.="COMMENT:\"<b>  Location          Current Average  Min     Max</b>\l\" \ \n";
                $cmd.="COMMENT:\\\\u COMMENT:\"${sun}\\r\" \ \n";                
            }
            $cmd.="LINE2:${vname}${colour}:\"${name}\" \ \n";
            $cmd.="GPRINT:${vname}:LAST:\"%2.1lf°C\" \ \n";
            $cmd.="GPRINT:${vname}:AVERAGE:\"%2.1lf°C\" \ \n";
            $cmd.="GPRINT:${vname}:MIN:\"%2.1lf°C\" \ \n";
            $cmd.="GPRINT:${vname}:MAX:\"%2.1lf°C\l\" \ \n";
            if($g==1) {
                $cmd.="COMMENT:\\\\u COMMENT:\"${twilight}\\r\" \ \n";
            }
            $g++;
        }
        $cmd.="HRULE:0#66CCFF:\"freezing\l\"";

        $cmd=str_replace("\ \n",'',$cmd);
        if($verbose) printf("Issuing graph command: %s\n",$cmd);
        $r=trim(`${cmd} 2>&1`);
        $images[$group_id][$period_name]=array('name'=>$period,'image'=>$r);
    }
    return $images;
}

function get_sunrise_sunset() {
    global $lat,$lon,$sunr,$suns,$dusk,$dawn,$sun,$twilight;

    $sunwait=explode("\n",trim(`sunwait report $lat $lon`));
    foreach($sunwait as $s) {
        if(preg_match('/Daylight:\ ([0-9]{2}):([0-9]{2})\ to\ ([0-9]{2}):([0-9]{2})$/',trim($s),$m)) {
            $sunr=($m[1]*3600) + ($m[2]*60);
            $suns=($m[3]*3600) + ($m[4]*60);
            $sun=sprintf("<b>Sunrise\: %s\:%s Sunset\: %s\:%s</b>",$m[1],$m[2],$m[3],$m[4]);
        }
        if(preg_match('/^with\ Nautical\ twilight:\ ([0-9]{2}):([0-9]{2})\ to\ ([0-9]{2}):([0-9]{2})$/',trim($s),$m)) {
            $dawn=($m[1]*3600) + ($m[2]*60);
            $dusk=($m[3]*3600) + ($m[4]*60);
            $twilight=sprintf("<b>  Light\: %s\:%s   Dark\: %s\:%s</b>",$m[1],$m[2],$m[3],$m[4]);
            break;
        }
    }
}

function write_index_page($images) {
    global $dir;
    $file=$dir.'/www/index.html';
    $date=date('D, d M Y H:i:s e');

    $content='<div class="row p-1">';
    $cols=0;
    foreach($images as $group_id=>$image_list) {
        $pillsnav=sprintf('
        <ul class="nav nav-pills mb-3" id="pills-%s-tab" role="tablist">',$group_id);
        $pillsdiv=sprintf('
        <div class="tab-content" id="pills-%s-tabContent">',$group_id);
        $g=1;
        foreach($image_list as $period_name=>$imagedata) {
            $pillsnav.=sprintf('
            <li class="nav-item" role="presentation">
                <a class="nav-link%s" id="pills-%s-%s-tab" data-toggle="pill" href="#pills-%s-%s" role="tab" aria-controls="pills-%s-%s" aria-selected="%s">%s</a>
            </li>',($g==1?' active':''),$group_id,$period_name,$group_id,$period_name,$group_id,$period_name,($g==1?'true':'false'),$imagedata['name']);
            $pillsdiv.=sprintf('    
            <div class="tab-pane fade%s" id="pills-%s-%s" role="tabpanel" aria-labelledby="pills-%s-%s-tab">
                %s
            </div>',($g==1?' show active':''),$group_id,$period_name,$group_id,$period_name,$imagedata['image']);
            $g++;
        }
        $pillsnav.='
        </ul>';
        $pillsdiv.='
        </div>';
        $content.=sprintf('
    <div class="col-md-6 border">%s%s
    </div>',$pillsnav,$pillsdiv);
        $cols++;
        if($cols%2===0) $content.="</div>\n<div class=\"row p-1\">\n";
    }
    $content.="\n</div>\n";

    $out='<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/dtd/xhtml11.dtd">
<html>
<head>
<title>Sensor 2</title>
<meta http-equiv="refresh" content="300" />
<meta http-equiv="pragma" content="no-cache" />
<meta http-equiv="cache-control" content="no-cache" />
<meta http-equiv="expires" content="'.$date.'" />
<meta http-equiv="generator" content="Hengwm-Sensors" />
<meta http-equiv="date" content="'.$date.'" />
<meta http-equiv="content-type" content="text/html; charset=iso-8859-1" />
<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css" />
<link rel="stylesheet" type="text/css" href="css/styles.css" />
<script src="js/jquery-3.5.1.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</head>
<body>
'.$content.'
</body>
</html>';

    if($fp=@fopen($file,'wb')) {
        fwrite($fp,$out);
        fclose($fp);
    } 

}

function scan_for_devices($i2c_dev,$start='03',$stop='77') {
    $dn=substr($i2c_dev,-1);
    $r=trim(`i2cdetect -y ${dn} 0x${start} 0x${stop}`);
    if($r=='') return false;
    $lines=explode("\n",$r);
    if(count($lines)==9) {
        $devices=array();
        for($y=1;$y<9;$y++) {
            for($x=0;$x<16;$x++) {
                $data=substr($lines[$y],4+($x*3),2);
                $hexaddr=sprintf('%02x',($y-1)*16+$x);
                if($data!='  ' and $data!='--') $devices[$hexaddr]=$data;
                if($hexaddr==$stop) break;
            }
        }
        return $devices;
    }
    return false;
}

function check_connected_devices($devices) {
    if(!isset($devices['18']) or $devices['18']!='UU') error("Error: I2C device on address 0x18 is not enabled!"); 
    if(!isset($devices['1f']) or $devices['1f']!='UU') error("Error: I2C device on address 0x1f is not enabled!");
}

function enumerate_temp_sensors() {
    $sensors=array();
    for($i=1;$i<=9;$i++) {
        $devicenode='/sys/devices/w1_bus_master'.$i;
        if(file_exists($devicenode)) {
            $r=trim(`ls -1 ${devicenode}`);
            $lines=explode("\n",$r);
            foreach($lines as $l) {
                if(preg_match('/^28\-[0-9a-f]{12}$/',$l)) {
                    $datafile=$devicenode.'/'.$l.'/temperature';
                    if(file_exists($datafile)) {
                        $data=trim(`cat ${datafile} 2>&1`);
                        if(preg_match('/^[0-9\.]+$/',$data)) $sensors[$l]=$data;
                    }
                }
            }
        }
    }
    return $sensors;
}


// ********************************************************************************Program Start

ob_implicit_flush ();
set_time_limit (0);
//command line parameter passing
$argc=$_SERVER["argc"];
$argv=$_SERVER["argv"]; //$argv is an array
$args=parse_args($argc,$argv);
if(count($args)<1) error(usage());
if(isset($args['h']) or isset($args['help'])) error(usage());
if(isset($args['d'])) $i2c_dev=$args['d']; else $i2c_dev='i2c-2';
if(isset($args['v'])) $verbose=true; else $verbose=false;
$dir=__DIR__;

@mkdir($dir.'/www');
@mkdir($dir.'/data');
@mkdir($dir.'/logs');

$devices=scan_for_devices($i2c_dev);
if(false===$devices) error("No I2C devices found on interface ${i2c_dev} !");

$sensor_list=array();
$group_list=array();
read_sensor_list();
read_group_list();
get_sunrise_sunset();

if(isset($args['p'])) { //probe
    `echo 0x18>/sys/bus/i2c/devices/${i2c_dev}/delete_device 2>/dev/null`;
    `echo 0x1f>/sys/bus/i2c/devices/${i2c_dev}/delete_device 2>/dev/null`;
    `echo ds2482 0x1f>/sys/bus/i2c/devices/${i2c_dev}/new_device 2>/dev/null`;
    `echo ds2482 0x18>/sys/bus/i2c/devices/${i2c_dev}/new_device 2>/dev/null`;
    sleep(1);
    check_connected_devices($devices);
    $sensors=enumerate_temp_sensors();
    $added=0;
    foreach($sensors as $k=>$v) {
        if(add_sensor_to_list($k,'New Sensor')) {
            printf( "Added new sensor with id: %s\n",$k);
            $added++;
        }
    }
    if($added) print "New sensor(s) were added. please edit the sensors-list.txt file and set the sensor's name/location!\n";
    exit(0);
}

if(isset($args['r'])) {
    check_connected_devices($devices);
    $temps=enumerate_temp_sensors();
    $sensors=get_sensor_definitions_by_id();
    //update rrd databases
    foreach($temps as $id=>$value) {
        if(!isset($sensors[$id])) {
            add_sensor_to_list($id,'New Sensor');
            $sensors=get_sensor_definitions_by_id();
        }
        if($verbose) printf("Sensor %s with name %s returns %s\n",$id,$sensors[$id]['name'],$value);
        $r=update_rrd_data($id,round($value/1000,2));
        if(true!==$r) error($r);
    }
    exit(0);
}

if(isset($args['g'])) {
    if(isset($args['D'])) sleep(30);
    $sensors=get_sensor_definitions_by_id();
    $graphs=array();
    $images=array();
    foreach($sensors as $id=>$sensor) {
        $graphs[$sensor['group']][]=array('id'=>$id,'name'=>$sensor['name'],'colour'=>$sensor['colour']);
    }
    $groups=get_group_definitions_by_id();
    foreach($graphs as $g=>$v) {
        if(isset($groups[$g])) {
            $images=array_merge($images,graph_rrd_data($g,$v));        
        }        
    }
    write_index_page($images);
    exit(0);
}

error(usage());