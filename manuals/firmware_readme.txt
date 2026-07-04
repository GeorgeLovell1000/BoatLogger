both lilygos were updated using:

https://adafruit.github.io/Adafruit_WebSerial_ESPTool/


This is working on the 7670G, but toasted the 7670E
ESP32_GENERIC_S3-SPIRAM_OCT-20260406-v1.28.0.bin


That's because the 7670E is not S3. 

Have now flashed "ESP32_GENERIC-SPIRAM-20251209-v1.27.0.bin" onto the 7670E and it's working. So looks like the same software main.py will run on both machines now.


