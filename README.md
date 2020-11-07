# hengwm-sensors

Sensor project using an A10 Lime ARMhf running Ubuntu 18.04

The temperature sensors are all on a one wire bus (this system has 9 1-wire busses).

The one wire buses are hosted by a DS2482-100 (for local sensors) and a DS2482-800 (remote sensors via CAT-5e cable).

The DS2482-100 is on an old Rasberry Pi hat that has an I2C 3.3-5V level shifter/buffer, and also a RS232 interface.
The PI hat is connected to various U-EXT pins on the A10 Lime and the I2C interface appears as /dev/i2c-2.
The DS2482-100 has an I2C address of 0x18, and the DS2482-800 of 0x1f.

The main script is called once per minute via a cron job to take temperature readings:

~~~
* * * * *       olimex  /home/olimex/src/hengwm-sensors/hengwm-sensors.sh.php -r >/dev/null
~~~

and once every 10 minutes to create the graphs:

~~~
*/10 * * * *    olimex  /home/olimex/src/hengwm-sensors/hengwm-sensors.sh.php -g -D >/dev/null
~~~

New sensors can be added to the system by running

~~~
./hengwm-sensors.sh.php -p
~~~

New default entries will be made in the config file sensor-list.txt which should then be properly named and given a graph group.
Graph groups are stored in group-list.txt

