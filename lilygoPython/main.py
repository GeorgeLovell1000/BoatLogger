# Core modules (Almost zero RAM cost)
import machine, time, os, math
from machine import Pin, SPI, PWM, Timer
import gc

# Assume this is loaded from config.json at boot
# e.g., SYSTEM_LOG_LEVEL = config.get("system", {}).get("logging_level", "INFO")
SYSTEM_LOG_LEVEL = "ERROR" 
LOG_LEVELS = {"DEBUG": 10, "INFO": 20, "WARNING": 30, "ERROR": 40, "CRITICAL": 50}
sd_ready = False # may change later if an SDcard is found and successfully mounted

# Other modules, some a bit more memory hungry
import usocket as socket 
import json
import network
import random
import urequests

def log_msg(cat, text, level="INFO", end_char="\n"):
    global SYSTEM_LOG_LEVEL, LOG_DEST, sd_ready
    """
    Writes to terminal/flash only if the message level meets or exceeds 
    the currently configured SYSTEM_LOG_LEVEL threshold.
    """
    msg_weight = LOG_LEVELS.get(level.upper(), 20)
    
    # Safely get system level (in case it wasn't declared globally yet)
    try: sys_weight = LOG_LEVELS.get(SYSTEM_LOG_LEVEL.upper(), 20)
    except: sys_weight = 20
    
    if msg_weight < sys_weight:
        return 
        
    t = time.localtime()
    timestamp = "[{:04d}-{:02d}-{:02d} {:02d}:{:02d}:{:02d}]".format(t[0], t[1], t[2], t[3], t[4], t[5]) if t[0] >= 2026 else "[Uptime: {}ms]".format(time.ticks_ms())
    clean_text = text.lstrip("\n")
    leading = "\n" * (len(text) - len(clean_text))
    
    # Prepend the level tag for easy reading
    full_line = "{}{} [{:<8}] [{:<8}] {}".format(leading, timestamp, level.upper(), cat, clean_text)
    
    # Safely default LOG_DEST if it hasn't been set by main yet
    try: dest = LOG_DEST
    except: dest = "both"
    
    # 1. Print to Terminal if configured
    if dest in ('terminal', 'both'): print(full_line, end=end_char)
    
    # 2. Save to SD Card instead of internal flash
    if dest in ('logfile', 'both'):
        if sd_ready:
            try:
                with open("/sd/logfile.txt", "a") as f: 
                    f.write(full_line + end_char)
            except: pass
        else:
            try:
                with open("/logfile.txt","a") as f:
                          f.write(full_line + end_char)
            except: pass

try:
    with open("config.json", "r") as f:
        boot_data = json.loads(f.read())
        SYSTEM_LOG_LEVEL = boot_data.get("system", {}).get("SYSTEM_LOG_LEVEL", "ERROR")
except:
    log_msg("FILES","Config file unreadable at boot. Adopting default (ERROR) SYSTEM_LOG_LEVEL safety defaults.","WARNING")


log_msg("SYSTEM","=== COLD HARDWARE BOOT ===","INFO")


# urequests uses a lot of memory, if it's still problematic on the other board then we can load, use, remove, cleanup like below:
# Only import when you actually need them (The "Late Import" Pattern)
# def upload_payload(url, data):
#     import urequests # Import inside the function!
#     # ... use urequests ...
#     # Once the function ends, the module stays, but 
#     # the RAM overhead is localized.

#import urequests
# ... perform your HTTP upload ...
#del urequests # Removes the module reference from memory
#import gc
#gc.collect()  # Forces the ESP32 to immediately reclaim the freed space


# rtc = machine.RTC()
# 
# # Check if waking from deep sleep or starting fresh
# if machine.reset_cause() == machine.DEEPSLEEP_RESET:
#     saved_bytes = rtc.memory()
#     total_uptime_seconds = int.from_bytes(saved_bytes, 'big') if saved_bytes else 0
# else:
#     total_uptime_seconds = 0
#     rtc.memory(total_uptime_seconds.to_bytes(4, 'big')) # Seed the RTC memory                                                                                                                                           try:
#     
# try:
#     global_wlan = network.WLAN(network.STA_IF)
#     global_wlan.active(False)
#     global_ap = network.WLAN(network.AP_IF)
#     global_ap.active(False)
#     print("✅ Wi-Fi DMA Memory Successfully Reserved.")
# except Exception as e:
#     print("❌ Wi-Fi Allocation Failed:", e)
# 
# last_loop_ticks = time.ticks_ms()                  
#                                             



# --- NEW: DYNAMIC HARDWARE BOOTSTRAP ---
hardware_version = "v1"  # Default safety fallback
boat_name = "NoVesselName"   # Default safety fallback
API_KEY   = "notGivingYouMyKey" # Default safety fallback

try:
    with open("config.json", "r") as f:
        boot_data = json.loads(f.read())
        hardware_version = boot_data.get("system", {}).get("hardware_version", "v1")
        APN = boot_data.get("system", {}).get("APN", "key")
        boat_name = boot_data.get("system", {}).get("boat_name", "NoVesselName")
        API_KEY = boot_data.get("system", {}).get("api_key", "MyNewBetterSecretKey999") # <-- NEW
except:
    log_msg("FILES","config.json file unreadable at boot. Adopting V1 safety defaults.","WARNING")



if hardware_version == "v2":
    log_msg("HARDWARE","Configuring Pins for V2 (ESP32-S3 / 7670G)...","INFO")
    LED_PIN = 48
    BUZZER_PIN = 1
    BATTERY_ADC_PIN = 5
    SDApin = 40
    SCLpin = 42
    SWITCH_MOORED_PIN = 6
    SWITCH_ANCHORED_PIN = 16
    
    # 2. Internal Board Routing (For your script's radio/SD commands)
    SD_CS               = 13  
    SD_SCK              = 14  
    SD_MISO             = 2  
    SD_MOSI             = 15

    UART_TX             = 11  
    UART_RX             = 10  
    BOARD_PWR_PIN       = 12  
    MODEM_PWRKEY        = 18   
    GPS_EN_PIN = 21

    slowestSpeed =  20000000 #  20000000
    workingSpeed =  40000000 #  80000000
    wifiSpeed    = 160000000 # 240000000

    
else:
    log_msg("HARDWARE","Configuring Pins for V1 (ESP32 / 7670E)...","INFO")
    LED_PIN = 33
    BUZZER_PIN = 25
    BATTERY_ADC_PIN = 34
    SDApin = 23
    SCLpin = 22
    SWITCH_MOORED_PIN = 35
    SWITCH_ANCHORED_PIN = 39

    # 2. Internal Board Routing (For your script's radio/SD commands)
    SD_CS   = 13     # Only this pin assigned to CS
    SD_SCK  = 14
    SD_MISO =  2
    SD_MOSI = 15  # Only this pin assigned to MOSI

    # --- PIN DEFINITIONS ---
    UART_TX, UART_RX = 26, 27
    BOARD_PWR_PIN = 12  
    MODEM_PWRKEY = 4    
    GPS_EN_PIN = 21      

    slowestSpeed =  20000000 #  20000000
    workingSpeed =  40000000 #  80000000
    wifiSpeed    = 160000000 # 240000000



# Initialize hardware serial once at boot
uart = machine.UART(1, baudrate=115200, tx=UART_TX, rx=UART_RX, timeout=2000, rxbuf=2048)


# Global timer variables for the background siren
siren_timer = machine.Timer(1)
siren_pwm = None
siren_step = 0
siren_max_steps = 60

rtc = machine.RTC()
MAX_REBOOTS = 5

#useWIFIrandThreshold=0.0 # if rand > threshold try wifi first, should always be zero unless testing cellular functions
failWIFIeveryNthGo = 1000






# Read memory once at boot
mem = rtc.memory()
reset_cause = machine.reset_cause()

# 1. Start by assuming a fresh session (Zero out all diagnostics)
boot_count = 0
total_uptime_seconds = 0
success_logs = 0
failed_logs = 0

# 2. Check if memory is structurally valid
if len(mem) == 16:
    if reset_cause == machine.DEEPSLEEP_RESET:
        # We woke from a planned Deep Sleep. Preserve EVERYTHING.
        boot_count = int.from_bytes(mem[0:4], 'big')
        total_uptime_seconds = int.from_bytes(mem[4:8], 'big')
        success_logs = int.from_bytes(mem[8:12], 'big')
        failed_logs = int.from_bytes(mem[12:16], 'big')
        
    elif reset_cause != machine.PWRON_RESET:
        # It's a CRASH (Soft reset, Hard reset, Watchdog). 
        # Preserve ONLY the boot_count so the "Pete Tong" shield still works.
        # Uptime, Success, and Fail logs are wiped clean to show the reboot on the website!
        boot_count = int.from_bytes(mem[0:4], 'big')


def save_state_to_rtc():
    """Packs the 4 core diagnostic counters into surviving RTC memory."""
    global boot_count, total_uptime_seconds, success_logs, failed_logs
    rtc.memory(
        boot_count.to_bytes(4, 'big') + 
        total_uptime_seconds.to_bytes(4, 'big') + 
        success_logs.to_bytes(4, 'big') + 
        failed_logs.to_bytes(4, 'big')
    )

# --- THE CRASH TRACKER FIX ---
# We increment the consecutive crash counter immediately on EVERY boot.
# If the system successfully reaches the end of its loop, it will reset this back to 0.
boot_count += 1
save_state_to_rtc()

# EMERGENCY SHIELD: The "Pete Tong" Limit
if boot_count > MAX_REBOOTS:
    # We are in a crash loop. Stop everything to save battery.
    log_msg("SYSTEM","Reboot loop detected. Entering safe mode.", level="CRITICAL")
    while True:
        # Rapid blink LED to indicate error state
        machine.Pin(LED_PIN, machine.Pin.OUT).value(not machine.Pin(LED_PIN, machine.Pin.OUT).value())
        time.sleep(0.5)


def check_log_size_limit(max_bytes=250000):
    """If the local log file exceeds 250KB, delete it to prevent storage exhaustion."""
    filepath = "/sd/logfile.txt" if sd_ready else "logfile.txt"
    try:
        file_stats = os.stat(filepath)
        if file_stats[6] > max_bytes: # Index 6 holds the file size in bytes
            os.remove(filepath)
            log_msg("SYSTEM","Local log file exceeded {} bytes and was purged.".format(max_bytes), level="WARNING")
    except:
        pass # File doesn't exist yet, which is fine


def get_backlog_filepath():
    """Returns the SD card path if mounted, otherwise internal flash."""
    global sd_ready
    return "/sd/failed_logs.txt" if sd_ready else "failed_logs.txt"

def save_failed_log_locally(param_str):
    """Appends a failed upload payload to the local backlog file."""
    filepath = get_backlog_filepath()
    try:
        with open(filepath, 'a') as f:
            f.write(param_str + '\n')
        log_msg("FILES","Payload saved locally. Will retry on next WIFI connection.", level="WARNING")
    except Exception as e:
        log_msg("FILES","Failed to write local log: {}".format(e), level="ERROR")

def process_backlog_on_wifi():
    """Reads the failed logs file and uploads them via Wi-Fi."""
    filepath = get_backlog_filepath()
    missed_params = []
    
    try:
        with open(filepath, 'r') as f:
            for line in f:
                if line.strip(): 
                    missed_params.append(line.strip())
    except:
        return # File doesn't exist or is empty

    if not missed_params: return

    log_msg("FILES","Backlog of {} logs discovered! Initiating recovery sequence...".format(len(missed_params)))
    failed_again = []
    success_count = 0
    
    for param_str in missed_params:
        try:
            delayed_url = "{}{}&delayed=1".format(HOME_GATEWAY, param_str)
            
            # --- FIX: Strict socket closure to survive rapid-fire uploads ---
            req_headers = {'Connection': 'close'}
            response = urequests.get(delayed_url, headers=req_headers, timeout=20)
            
            if response.status_code == 200:
                success_count += 1
            else:
                failed_again.append(param_str)
                
            response.close()
            gc.collect()
        except:
            failed_again.append(param_str)
            gc.collect()
            
        time.sleep_ms(300) 
            
    log_msg("FILES","Successfully recovered and uploaded {} logs.".format(success_count))
    
    if len(failed_again) == 0:
        try: os.remove(filepath)
        except: pass
    else:
        try:
            with open(filepath, 'w') as f:
                for p in failed_again:
                    f.write(p + '\n')
        except:
            log_msg("FILES","Could not rewrite backlog file.", level="ERROR")


def get_uptime_safely():
    global total_uptime_seconds, last_loop_ticks
    current = time.ticks_ms()
    
    # Safely calculate elapsed milliseconds (handles the 6.2-day rollover)
    elapsed_ms = time.ticks_diff(current, last_loop_ticks) 
    
    if elapsed_ms >= 1000:
        seconds_passed = elapsed_ms // 1000
        total_uptime_seconds += seconds_passed
        
        # Advance the baseline
        last_loop_ticks = time.ticks_add(last_loop_ticks, seconds_passed * 1000)
        
        # INSTANT BACKUP: Save the new totals to RTC memory.
        save_state_to_rtc()
        
    return total_uptime_seconds

def _siren_callback(timer):
    global siren_step, siren_pwm
    if siren_pwm is None: return
    
    if siren_step < siren_max_steps:
        
        if True:

            # A 5-step cycle where each step is 250ms. Total cycle = 1.25s
            cycle_phase = siren_step % 5
            
            # Phase 0: Start the 500Hz tone. 
            # (It will stay on through Phase 1 and Phase 2, totaling 750ms)
            if cycle_phase == 0:
                siren_pwm.freq(500)       
                siren_pwm.duty(512)       
                
            # Phase 3: Turn the tone off. 
            # (It will stay off through Phase 4, totaling 500ms silence)
            elif cycle_phase == 3:
                siren_pwm.duty(0)


        else:
            # A 4-step cycle: High -> Silence -> Low -> Silence
            cycle_phase = siren_step % 4

            if cycle_phase == 0:
                siren_pwm.freq(580)       # High tone
                siren_pwm.duty(512)       # Standardized to 10-bit API
            elif cycle_phase == 2:
                siren_pwm.freq(435)       # Low tone
                siren_pwm.duty(512)       # Standardized to 10-bit API
            else:
                siren_pwm.duty(0)         # Articulation gap (Silence)
            
        siren_step += 1
    else:
        silence_alarm()
        log_msg("ALARM","Siren max duration reached. Silencing.")

def trigger_anchor_alarm(duration_seconds=60):
    global siren_step, siren_max_steps, siren_pwm
    siren_step = 0
    # Multiplied by 4 because the timer runs 4 times per second now
    siren_max_steps = duration_seconds * 4 
    
    if siren_pwm is None:
        siren_pwm = machine.PWM(machine.Pin(BUZZER_PIN))
    
    log_msg("ALARM","High-precedence anchor drift detected! Engaging siren.")
    
    # Accelerated to 250ms to accommodate the silent articulation gaps
    siren_timer.init(period=250, mode=machine.Timer.PERIODIC, callback=_siren_callback)

def silence_alarm():
    global siren_pwm, buzzer
    siren_timer.deinit()
    
    if siren_pwm is not None:
        siren_pwm.deinit() # Surrender the PWM hardware block
        siren_pwm = None
        
    # Crucial: Restore standard pin mode so the play_tune() beeps still work
    buzzer = machine.Pin(BUZZER_PIN, machine.Pin.OUT)
    buzzer.value(0)

def play_tune(tune_name="reboot"):
    # 1. Take over the buzzer pin with a PWM timer
    pwm_buzzer = machine.PWM(machine.Pin(BUZZER_PIN))
    
    # 2. Universal Note Frequencies (in Hertz)
    REST = 0
    C4  = 262
    D4  = 294
    Eb4 = 311
    E4  = 330
    F4  = 349
    Gb4 = 370
    G4  = 392
    A4  = 440
    Bb4 = 466
    C5  = 523
    D5  = 587
    Eb5 = 622
    E5  = 659
    
    # 3. Define the Melodies Library
    tunes = {
        "imperial_march": [
            (G4, 500), (G4, 500), (G4, 500), (Eb4, 350), (Bb4, 150),
            (G4, 500), (Eb4, 350), (Bb4, 150), (G4, 1000), (REST, 200),
            (D5, 500), (D5, 500), (D5, 500), (Eb5, 350), (Bb4, 150),
            (Gb4, 500), (Eb4, 350), (Bb4, 150), (G4, 1000)
        ],
        "reboot": [
            # A fast, cheerful ascending arpeggio (Classic power-up chime)
            (C4, 100), (E4, 100), (G4, 100), (C5, 400)
        ],
        "error": [
            # A distinct "uh-oh" descending two-tone
            (F4, 200), (REST, 50), (D4, 500)
        ]
    }
    
    # Safely fetch the requested tune, default to reboot if misspelled
    melody = tunes.get(tune_name, tunes["reboot"])
    
    log_msg("AUDIO","Playing tune: {}".format(tune_name), level="INFO")
    
    # 4. Loop through the notes and play them
    for freq, duration in melody:
        if freq == REST:
            pwm_buzzer.duty(0) # Silence
        else:
            pwm_buzzer.freq(freq)
            pwm_buzzer.duty(512) # 50% duty cycle (max volume)
            
        time.sleep_ms(duration)
        
        # Tiny 50ms pause between notes for articulation
        pwm_buzzer.duty(0)
        time.sleep_ms(50)
        
    # 5. Clean up and restore the buzzer to a standard Output pin
    pwm_buzzer.deinit() 
    global buzzer
    buzzer = machine.Pin(BUZZER_PIN, machine.Pin.OUT)
    buzzer.value(0)

def run_emergency_portal(duration_sec=300):
    global sd_ready  # Required to check SD card status
    
    portal_start_switch = read_physical_toggle_switch()
    
    # ADD THIS PROTECTION
    log_msg("PORTAL","Checking modem availability before override...")
    uart.write("AT\r\n") 
    time.sleep(1)
    if not uart.any():
        log_msg("PORTAL","Modem busy or asleep. Forcing hard power cycle.", level="WARNING")
        pwr_rail.value(0); time.sleep(2); pwr_rail.value(1); time.sleep(5)    
    
    """Launches local access point using sequenced radio initialization to prevent conflict."""
    log_msg("SYSTEM","==============================================")
    log_msg("SYSTEM","=== [PORTAL] EXECUTING OVERRIDE INTRUSION ===")
    log_msg("SYSTEM","==============================================")
    
    # 1. Wake up the cell modem and let it finish its loud tower handshake FIRST
    log_msg("PORTAL","Waking up cell modem RF deck...")
    talk("AT+CFUN=1")
    time.sleep(3) # Give it plenty of time to resolve network registration noise
    
    # 2. Query the operator code cleanly while cellular is stable
    cops_reply = talk("AT+COPS?")
    provider = "4G" # Fallback tag

    if '"' in cops_reply:
        try:
            raw_code = cops_reply.split('"')[1]
            # Map standard UK Mobile Network Codes (MNC)
            if raw_code in ["23430", "23433", "23434"]: provider = "EE"
            elif raw_code in ["23410", "23402", "23411"]: provider = "O2"
            elif raw_code in ["23415", "23491"]:          provider = "Voda"
            elif raw_code in ["23420", "23486"]:          provider = "Three"
            else: provider = "Roam" # Non-standard or cross-roaming gateway
        except:
            provider = "4G"
        
    cell_status_string = "4g-{}_SuspNetblock*{}".format(provider, cell_tier_index) if cell_tier_index > 0 else "4g-{}".format(provider)
    log_msg("PORTAL","Cellular metrics captured: {}".format(cell_status_string))

    # 3. Purge the background tracking client radio to break internal driver locks
    log_msg("PORTAL","Purging client radios and initializing Wi-Fi AP stack...")
    try:
        sta = network.WLAN(network.STA_IF)
        sta.active(False)  # <-- FORCE DEACTIVATE CLIENT STACK
    except:
        pass
    time.sleep_ms(200)

    # Spin up the Access Point cleanly using standard hyphens (iOS friendly)
    ap = global_ap
    ap.active(True)
    ap.config(essid="BoatLogger-Emergency-Net", authmode=network.AUTH_OPEN)
    
    time.sleep(1)
    
    local_ip = ap.ifconfig()[0]
    log_msg("PORTAL","Broadcast Active -> SSID: BoatLogger-Emergency-Net")
    log_msg("PORTAL","Direct Mobile Destination: http://{}".format(local_ip))
    
    try: buzzer.value(1); time.sleep_ms(100); buzzer.value(0)
    except: pass
    
    # 4. Establish standard web listening endpoint
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.bind(('', 80))
    s.listen(1)
    s.setblocking(False)
    
    start_time = time.time()
    
    while (time.time() - start_time) < duration_sec:
        current_sw = read_physical_toggle_switch()
        
        # Check if the switch has moved away from the position it was in when the portal started
        if current_sw != portal_start_switch:
            time.sleep_ms(200) # Wait to ensure it's a real toggle
            if read_physical_toggle_switch() == current_sw:
                log_msg("PORTAL","Confirmed mechanical override. Exiting.")
                break
            
        try:
            conn, addr = s.accept()
            
            # Read the raw bytes first
            raw_data = conn.recv(4096)
            if not raw_data:
                conn.close()
                continue
                
            # Decode safely, bypassing any hidden non-text characters sent by iOS
            request = raw_data.decode('utf-8', 'ignore')
            
            # --- CASE 1: USER IS SUBMITTING CHANGES (TAP SAVE OR DISCARD) ---
            if "POST /save" in request:
                log_msg("PORTAL","Form submission captured from mobile client.")
                
                # Check if the user opted to discard changes early
                if "portal_action=close" in request:
                    log_msg("PORTAL","Discard action selected. Resuming regular loop operations.")
                    conn.send('HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n')
                    conn.sendall('<html><body><h2>Resuming logger routines...</h2></body></html>')
                    conn.close()
                    break # Breaks out of the 5-minute AP portal to resume logging!
                
                # --- NEW URL DECODER ---
                def decode_url(s):
                    res = s.replace('+', ' ')
                    final_str, i = "", 0
                    while i < len(res):
                        if res[i] == '%' and i + 2 < len(res):
                            try:
                                final_str += chr(int(res[i+1:i+3], 16))
                                i += 3
                                continue
                            except: pass
                        final_str += res[i]
                        i += 1
                    return final_str

                def get_form_val(key, default_val):
                    if key + "=" in request:
                        part = request.split(key + "=")[1].split("&")[0].split(" HTTP")[0].strip()
                        return decode_url(part)
                    return default_val

                # Parse out the standard values submitted by your phone screen
                mooring_val = get_form_val("mooring_sleep", "5")
                anchor_val = get_form_val("anchor_sleep", "5")
                travelling_val = get_form_val("travelling_sleep", "5")
                log_pipe_val = get_form_val("logging_mode", "both")
                phone_num_val = get_form_val("mobile_num", "")
                alarm_trigger_val = get_form_val("sms_alarm_trigger", "2")
                low_batt_val = True if "sms_low_batt=true" in request else False
                high_temp_val = True if "sms_high_temp=true" in request else False
                out_of_bounds_val = True if "sms_out_of_bounds=true" in request else False
                
                # --- NEW: Extract and update Wi-Fi Networks ---
                wifi_payload_str = get_form_val("wifi_payload", "")
                if wifi_payload_str:
                    try:
                        config["networks"] = json.loads(wifi_payload_str)
                    except Exception as e:
                        log_msg("PORTAL","Error parsing Wi-Fi JSON: {}".format(e), level="ERROR")

                # 1. Update Sleep Intervals (Convert Form Minutes into Config Seconds!)
                config["mooring"]["sleep_sec"] = int(mooring_val) * 60
                config["anchor"]["sleep_sec"] = int(anchor_val) * 60
                config["travelling"]["sleep_sec"] = int(travelling_val) * 60
                
                # 2. Update System Outputs
                config["system"]["log_dest"] = log_pipe_val
                
                # 3. Track Mobile Number Changes for Handshaking
                old_phone = config.get("alerts", {}).get("mobile_number", "0000000")
                queue_handshake = True if phone_num_val != old_phone and phone_num_val != "" else False
                
                # 4. Save Core Alerts Group
                config["alerts"]["mobile_number"] = phone_num_val
                
                # Add dynamic keys to your alerts group if your main script uses them:
                config["alerts"]["sms_alarm_trigger_cycle"] = int(alarm_trigger_val)
                config["alerts"]["sms_on_low_battery"] = low_batt_val
                config["alerts"]["sms_on_high_temp"] = high_temp_val                
                config["alerts"]["sms_on_out_of_bounds"] = out_of_bounds_val
                
                # Save the new values directly into internal flash storage config file
                save_config(config)
                log_msg("[PORTAL ] Configuration written to config.json safely.")
                
                # Send success response page to your phone screen
                conn.send('HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n')
                conn.sendall('<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="background:#121212;color:#fff;text-align:center;padding-top:50px;font-family:sans-serif;"><h2>Settings Saved!</h2><p>Resuming system logging loops...</p></body></html>')
                conn.close()
                
                # Fire confirmation chirps on your buzzer
                try:
                    buzzer.value(1); time.sleep_ms(80); buzzer.value(0); time.sleep_ms(80)
                    buzzer.value(1); time.sleep_ms(80); buzzer.value(0)
                except: pass
                
                if queue_handshake:
                    log_msg("PORTAL","Primary contact number altered. Queuing verification test step...")
                    # Add an operation flag call or execute your test sms method directly here!

                break # Exit the portal loop and return execution straight back to regular tracking!

            # --- CASE 2: SERVE THE LOG VIEWER HTML PAGE ---
            elif "GET /viewLogs" in request:
                log_msg("[PORTAL ] Client navigated to Log Viewer.")
                conn.send('HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n')
                try:
                    with open("viewLogs.html", "r") as f:
                        for line in f:
                            conn.sendall(line)
                except Exception as file_err:
                    log_msg("[PORTAL ] Error serving viewLogs.html: {}".format(file_err), level="ERROR")
                    conn.sendall("<html><body>Error loading viewLogs.html</body></html>")
                conn.close()
                continue
                
            # --- CASE 3: SERVE TELEMETRY LOGS TO BROWSER (THE FETCH CALL) ---
            elif "GET /read_logs" in request:
                log_msg("PORTAL","Client requested telemetry log dump.")
                conn.send('HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n')
                
                # Determine log path dynamically
                filepath = "/sd/logfile.txt" if sd_ready else "logfile.txt"
                
                try:
                    with open(filepath, "r") as f:
                        conn.sendall(f.read())
                except:
                    conn.sendall("Log file empty or missing.")
                conn.close()
                continue # Return to loop to keep the portal open

            # --- CASE 4: STANDARD INITIAL PAGE LOADING ---
            else:
                try:
                    # 1. Parse current config settings
                    mooring_mins = str(int(config.get("mooring", {}).get("sleep_sec", 300)) // 60)
                    anchor_mins = str(int(config.get("anchor", {}).get("sleep_sec", 240)) // 60)
                    travelling_mins = str(int(config.get("travelling", {}).get("sleep_sec", 60)) // 60)
                    batt_checked = "checked" if config.get("alerts", {}).get("sms_on_low_battery", False) else ""
                    temp_checked = "checked" if config.get("alerts", {}).get("sms_on_high_temp", False) else ""
                    bounds_checked = "checked" if config.get("alerts", {}).get("sms_on_out_of_bounds", True) else ""
                    
                    # Calculate Battery SOC
                    soc = min(100, max(0, int(((battery_v - 11.5) / (12.8 - 11.5)) * 100)))
                    
                    # 2. Send HTTP Headers FIRST
                    conn.send('HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n')
                    
                    # 3. Stream file line-by-line (Zero Memory Overhead)
                    with open("/portal.html", "r") as f:
                        for line in f:
                            # Only run replacements if the line contains a tag bracket
                            if "[" in line: 
                                line = line.replace("[STATUS]", boat_status.upper())
                                line = line.replace("[LAT]", str(latitude))
                                line = line.replace("[LNG]", str(longitude))
                                line = line.replace("[CABIN_TEMP]", str(temperature))
                                line = line.replace("[HUMIDITY]", str(humidity))
                                line = line.replace("[MODEM_TEMP]", str(modemTemperature))
                                line = line.replace("[BATT_V]", str(battery_v))
                                line = line.replace("[SOC]", str(soc))
                                
                                line = line.replace("[PHONE]", config.get("alerts", {}).get("mobile_number", ""))
                                line = line.replace("[BATT_CHK]", batt_checked)
                                line = line.replace("[TEMP_CHK]", temp_checked)
                                line = line.replace("[BOUNDS_CHK]", bounds_checked)                                
                                line = line.replace("[M_SLEEP]", mooring_mins)
                                line = line.replace("[A_SLEEP]", anchor_mins)
                                line = line.replace("[T_SLEEP]", travelling_mins)
                                line = line.replace("[LOG_MODE]", config.get("system", {}).get("log_dest", "both"))
                                line = line.replace("[SMS_TRIG]", str(config.get("alerts", {}).get("sms_alarm_trigger_cycle", "2")))
                                # --- NEW: Inject Wi-Fi JSON array ---
                                try:
                                    wifi_json = json.dumps(config.get("networks", {}))
                                except:
                                    wifi_json = "{}"
                                line = line.replace("[NETWORKS_JSON]", wifi_json)
                            # Push the processed line directly to the socket
                            conn.sendall(line)
                            
                except Exception as file_err:
                    log_msg("PORTAL","File serving error: {}".format(file_err), level="ERROR")
                
                conn.close()
                log_msg("PORTAL","Control Deck served to client: {}".format(addr[0]))
                
        except OSError:
            pass
            
        time.sleep_ms(100)
        
    # --- TEARDOWN LOOP WINDOW EXPIRED OR OVERRIDDEN BY USER ---
    s.close()
    ap.active(False)
    
    play_tune("imperial_march")   
    
    log_msg("PORTAL","Portal window closed. Executing clean system reboot...")
    time.sleep_ms(500) # Short pause to let the log print finish over serial
    
    import machine
    machine.reset()
    
def read_cabin_climate():
    global temperature, humidity, i2c
    try:
        # Send high-repeatability measurement command to SHT40 (0x44)
        i2c.writeto(0x44, b'\xFD')
        time.sleep_ms(15)
        
        # Pull 6-byte raw matrix
        data = i2c.readfrom(0x44, 6)
        
        raw_temp = (data[0] << 8) | data[1]
        raw_hum  = (data[3] << 8) | data[4]
        
        # Convert values using factory standard formulas
        temperature = round(-45.0 + 175.0 * (raw_temp / 65535.0), 1)
        raw_humidity = -6.0 + 125.0 * (raw_hum / 65535.0)
        humidity = round(max(0.0, min(100.0, raw_humidity)), 1)
        
        log_msg("HARDWARE","Cabin Environment Data -> Temp: {}°C | Hum: {}%".format(temperature, humidity))
        return True
    except Exception as e:
        log_msg("SYSTEM","SHT40 climate sensor read timeout/error: {}".format(e), level="ERROR")
        return False


def read_barometric_pressure():
    """Scans for BMP280 on the standard I2C bus and extracts calibrated hPa."""
    global pressure, i2c
    
    # 1. Detect active address (0x76 or 0x77 depending on module manufacturer)
    addr = None
    for target in [0x76, 0x77]:
        try:
            i2c.writeto(target, b'\xD0')
            if i2c.readfrom(target, 1)[0] == 0x58:
                addr = target
                break
        except: pass
        
    if not addr:
        log_msg("SYSTEM","BMP280 Barometer module not detected on I2C bus.", level="WARNING")
        return False

    try:
        # 2. Fetch factory compensation coefficients
        i2c.writeto(addr, b'\x88')
        cal = i2c.readfrom(addr, 24)
        
        # Unpack little-endian structural integers cleanly
        def r_short(offset, signed=True):
            v = (cal[offset+1] << 8) | cal[offset]
            if signed and (v & 0x8000): v -= 65536
            return v

        dig_T1 = r_short(0, signed=False)
        dig_T2 = r_short(2)
        dig_T3 = r_short(4)
        dig_P1 = r_short(6, signed=False)
        dig_P2 = r_short(8)
        dig_P3 = r_short(10)
        dig_P4 = r_short(12)
        dig_P5 = r_short(14)
        dig_P6 = r_short(16)
        dig_P7 = r_short(18)
        dig_P8 = r_short(20)
        dig_P9 = r_short(22)

        # 3. Trigger Forced Measurement Cycle
        i2c.writeto_mem(addr, 0xF4, b'\x2E') # Control register: Forced mode, x1 oversampling
        time.sleep_ms(20)

        # 4. Pull uncompensated environmental data
        i2c.writeto(addr, b'\xF7')
        raw = i2c.readfrom(addr, 6)
        raw_press = (raw[0] << 12) | (raw[1] << 4) | (raw[2] >> 4)
        raw_temp  = (raw[3] << 12) | (raw[4] << 4) | (raw[5] >> 4)

        # 5. Apply compensation formulas (Bosch Datasheet float variants)
        v1 = (raw_temp / 16384.0 - dig_T1 / 1024.0) * dig_T2
        v2 = ((raw_temp / 131072.0 - dig_T1 / 8192.0) ** 2) * dig_T3
        t_fine = v1 + v2

        v1 = (t_fine / 2.0) - 64000.0
        v2 = v1 * v1 * dig_P6 / 32768.0
        v2 = v2 + v1 * dig_P5 * 2.0
        v2 = (v2 / 4.0) + (dig_P4 * 65536.0)
        v1 = (dig_P3 * v1 * v1 / 524288.0 + dig_P2 * v1) / 524288.0
        v1 = (1.0 + v1 / 32768.0) * dig_P1
        
        if v1 == 0.0: return False
        
        p = 1048576.0 - raw_press
        p = (p - (v2 / 4096.0)) * 6250.0 / v1
        v1 = dig_P9 * p * p / 2147483648.0
        v2 = p * dig_P8 / 32768.0
        
        pressure = round((p + (v1 + v2 + dig_P7) / 16.0) / 100.0, 1) # Output in hPa/millibars
        log_msg("HARDWARE","Atmospheric Pressure: {} hPa".format(pressure))
        return True
    except Exception as e:
        log_msg("SYSTEM","Error processing BMP280 math: {}".format(e), level="ERROR")
        return False

def read_modem_temperature():
    global modemTemperature # <-- Updated to protect cabin temperature from being overwritten
    try:
        res = talk("AT+CPMUTEMP", wait=300)
        if "+CPMUTEMP:" in res:
            temp_raw = res.split("+CPMUTEMP:")[1].splitlines()[0].strip()
            if " " in temp_raw:
                temp_raw = temp_raw.split()[0]
                
            modemTemperature = float(temp_raw)
            log_msg("HARDWARE","Modem Core Temperature: {}°C".format(modemTemperature))
            return True
    except Exception as e:
        log_msg("SYSTEM","Temperature hardware query failed: {}".format(e), level="ERROR")
    return False

def read_logger_voltage():
    try:
        # Load the live calibration data
        cfg = load_config()
        adc_ref = cfg.get("system", {}).get("v_adc_ref", 3.3)
        divider = cfg.get("system", {}).get("v_divider_ratio", 6.247)
        
        total = 0
        for _ in range(10):
            total += adc.read()
            time.sleep_ms(5)
            
        raw_average = total / 10.0
        pin_voltage = (raw_average / 4095.0) * adc_ref
        
        return round(pin_voltage * divider, 2)
    except:
        return 12.0
    
def read_physical_toggle_switch():
    if sw_moored.value() == 0: return "moored"
    if sw_anchored.value() == 0: return "anchored"
    return "travelling"
    
def load_config():
    try:
        with open("config.json", "r") as f: return json.load(f)
    except:
        default_config = {
            "mooring": {"lat": 0.00000, "lng": 0.00000, "geofence_meters": 20.0, "sleep_sec": 300},
            "anchor": {"lat": 0.00000, "lng": 0.00000, "geofence_meters": 20.0, "sleep_sec": 120},
            "travelling": {"sleep_sec": 60},
            "alerts": {"mobile_number": "YOUR_MOBILE_NUMBER", "sms_on_out_of_bounds": True, "sms_on_location_loss": False},
            "system": {
                "log_dest": "both", 
                "current_status": "moored", 
                "hardware_version": "v1", 
                "boat_name": "NoVesselName",
                "v_adc_ref": 3.3,           # <--- NEW: Internal ADC Reference
                "v_divider_ratio": 6.247    # <--- NEW: Resistor Divider Math
            },
            "networks": {}
        }
        save_config(default_config)
        return default_config

def save_config(config_data):
    try:
        # Convert the dictionary to a raw string first
        raw_json = json.dumps(config_data)
        
        # Manually inject newlines and indents so it is easy to read!
        pretty_json = raw_json.replace('{"', '{\n  "').replace(',"', ',\n  "').replace('}}', '}\n}')
        
        with open("config.json", "w") as f: 
            f.write(pretty_json)
        return True
    except: 
        return False

def kill_all_peripherals():
    # 1. Modem Shutdown
    talk("AT+CPOF", wait=1000) # The "hard power off" command for SIM7670
    
    # 2. Force GPIOs LOW
    # This ensures no current leaks back into the modem/GPS through the data pins
    for pin_num in [16, 17, 4, 18, 19]: # Update with your specific modem/GPS pins
        p = Pin(pin_num, Pin.OUT)
        p.value(0)
    
    log_msg("SYSTEM","Peripherals killed.")


                          
def alert_fail():
    for _ in range(4):
        buzzer.value(1); time.sleep_ms(120); buzzer.value(0); time.sleep_ms(80)

def alert_alarm():
    for _ in range(3):
        buzzer.value(1); time.sleep_ms(600); buzzer.value(0); time.sleep_ms(200)

def flash_recovery_pattern():
    for _ in range(3):
        led.value(0); time.sleep_ms(100); led.value(1); time.sleep_ms(100)
    time.sleep_ms(1200)

def talk(cmd, wait=1000):
    while uart.any(): uart.read()
    uart.write(cmd + "\r\n")
    time.sleep_ms(wait)
    res = ""
    while uart.any():
        chunk = uart.read()
        if chunk:
            try: res += chunk.decode('utf-8')
            except:
                for byte in chunk:
                    if 32 <= byte <= 126 or byte in (10, 13): res += chr(byte)
        time.sleep_ms(50)
        
    if "+CGNSSINFO:" in res:
        try:
            parts = res.split("+CGNSSINFO:")[1].splitlines()[0].split(",")
            if len(parts) > 10:
                d_str, t_str = parts[9].strip(), parts[10].strip()
                if len(d_str) == 6 and len(t_str) >= 6:
                    machine.RTC().datetime((2000+int(d_str[4:6]), int(d_str[2:4]), int(d_str[0:2]), 0, int(t_str[0:2]), int(t_str[2:4]), int(t_str[4:6]), 0))                    
        except: pass
    clean_res = res.replace(cmd, "").strip()
    flat_res = " | ".join([line.strip() for line in clean_res.splitlines() if line.strip()])
    log_msg("CELL","{:<36} -> {}".format(cmd, flat_res if flat_res else "NO RESPONSE"), level="DEBUG")
    return res.strip()


def send_sms(phone_number, message):
    if not phone_number or len(phone_number) < 5:
        log_msg("SMS","Aborted: No valid mobile number configured.", level="WARNING")
        return False
        
    log_msg("SMS","Attempting to dispatch alert to {}...".format(phone_number))
    
    # --- FIX 4G ROUTING ---
    # Force modem to use legacy Circuit Switched (CS) domain for SMS
    # bypassing the 4G IMS layer that IoT SIMs usually reject.
    talk("AT+CGSMS=1", wait=300)
    # ----------------------
    
    # Force standard GSM alphabet
    talk('AT+CSCS="GSM"', wait=300) 
    
    # 1. Set modem to SMS Text Mode
    talk("AT+CMGF=1", wait=500)
    
    # 2. Open the message prompt for the target number
    prompt = talk('AT+CMGS="{}"'.format(phone_number), wait=500)
    
    if ">" in prompt:
        # 3. Send the message string followed by CTRL+Z (ASCII 26) to execute
        uart.write(message + chr(26))
        
        # 4. Wait up to 5 seconds for the network to confirm transmission
        time.sleep(5) 
        res = ""
        while uart.any():
            chunk = uart.read()
            if chunk:
                try: res += chunk.decode('utf-8')
                except: pass
            time.sleep_ms(50)
            
        if "+CMGS:" in res:
            log_msg("SMS","Message transmitted successfully!")
            return True
        else:
            clean_res = res.strip().replace('\n', ' ')
            log_msg("SMS","Transmission failed. Network response: {}".format(clean_res), level="ERROR")
            return False
    else:
        log_msg("SMS","Modem refused prompt. Sending ESC to clear buffer.", level="ERROR")
        uart.write(chr(27)) # Send ESC to cancel if stuck
        return False


def calculate_drift(lat1, lng1, lat2, lng2):
    R = 6371000.0
    x = (math.radians(lng2) - math.radians(lng1)) * math.cos(math.radians((lat1 + lat2) / 2.0))
    y = math.radians(lat2) - math.radians(lat1)
    return math.sqrt(x*x + y*y) * R

def parse_gnss_string(raw_response):
    global latitude, longitude
    try:
        clean_str = raw_response.split("+CGNSSINFO:")[1].split("OK")[0].strip()
        data = clean_str.split(',')
        hdop = float(data[15])
        if hdop == 99.99 or hdop > 4.0: return False
        raw_lat, raw_lng = data[5], data[7]
        if not raw_lat or not raw_lng: return False
        latitude = round(float(raw_lat) if data[6] == 'N' else -float(raw_lat), 5)
        longitude = round(float(raw_lng) if data[8] == 'E' else -float(raw_lng), 5)
        log_msg("GNSS","VERIFIED LOCK -> Lat: {}, Lng: {} (HDOP: {})".format(latitude, longitude, hdop))
        return True
    except: return False


def safe_set_freq(target_speed):
    """Safely steps CPU frequency to prevent PSRAM sync loss and voltage brownouts."""
    try:
        current_speed = machine.freq()
        if current_speed == target_speed:
            log_msg("SYSTEM","CPU Frequency already @: {} Hz".format(target_speed), level="DEBUG")
            return
            
        # Standard ESP32 hardware clock tiers
        tiers = [20000000, 40000000, 80000000, 160000000, 240000000]
        
        # If speeds are non-standard, perform a direct jump to avoid math errors
        if current_speed not in tiers or target_speed not in tiers:
            log_msg("SYSTEM","CPU Frequency not in tier list. Jumping straight to: {} Hz".format(target_speed), level="DEBUG")
            machine.freq(target_speed)
            return
            
        start_idx = tiers.index(current_speed)
        end_idx = tiers.index(target_speed)
        
        # Determine if we are stepping up (+1) or stepping down (-1)
        step_dir = 1 if end_idx > start_idx else -1
        
        # Walk through the tiers one by one
        for i in range(start_idx + step_dir, end_idx + step_dir, step_dir):
            machine.freq(tiers[i])
            time.sleep_ms(20) # 20ms stability pause for PLL and voltage regulator to settle
            
        log_msg("SYSTEM","CPU Frequency safely stepped to: {} Hz".format(target_speed), level="DEBUG")

        
    except Exception as e:
        log_msg("SYSTEM","Failed to shift CPU clock: {}".format(e), level="ERROR")


# --- REBUILT STRATEGY 1: MULTI-NETWORK WIFI PIPELINE ---
def try_wifi_pathway(f_lat, f_lng, f_rad):
    global success_logs
                   
    log_msg("WIFI","Beginning WIFI CHECK ---", level="INFO")
    
    # --- FIX: BOOST CPU FOR CRYPTO MATH ---
    safe_set_freq(wifiSpeed)
       
    config = load_config()
    wifi_dict = config.get("networks", {})

    log_msg("WIFI","Loaded WIFI Catalogue ", level="INFO")

    # --- FIX: PURGE ZOMBIE STATE ---
    wlan = network.WLAN(network.STA_IF)
    wlan.active(False)
    time.sleep_ms(200)
    wlan.active(True)
    try: wlan.disconnect() # Force clear stale MACs
    except: pass
    time.sleep_ms(200)

    log_msg("WIFI","Beginning WIFI Scan ---", level="INFO")

    try:
        scan_results = wlan.scan()
        visible_ssids = [r[0].decode('utf-8', 'ignore') for r in scan_results]
    except Exception as e:
        log_msg("WIFI","Scan failed: {}".format(e), level="WARNING")
        safe_set_freq(workingSpeed)
        return False

    matched_ssids = [ssid for ssid in wifi_dict.keys() if ssid in visible_ssids]
    log_msg("WIFI","Scan Complete.", level="INFO")
    
    if not matched_ssids:
        log_msg("WIFI","No known networks visible.", level="INFO")
        safe_set_freq(workingSpeed)
        return False

    upload_success = False

    for target_ssid in matched_ssids:
        connection_successful = False
        attempts_made = 0

        log_msg("WIFI","Connecting: " + target_ssid, level="INFO")


        # --- FIX: 3-TRY AGGRESSIVE RETRY LOOP ---
        for attempt in range(3):
            attempts_made += 1
            wlan.connect(target_ssid, wifi_dict[target_ssid])
            
            timeout = 15
            while timeout > 0 and not wlan.isconnected():
                time.sleep(1); timeout -= 1
                
            if wlan.isconnected():
                connection_successful = True
                break
            else:
                wlan.disconnect()
                time.sleep(1)
        
        if connection_successful:
            log_msg("WIFI","Connected to '{}' after {} attempt(s) -> IP: {}".format(target_ssid, attempts_made, wlan.ifconfig()[0]), level="INFO")
            
            uptime_sec = get_uptime_safely()
            t = time.localtime()
            url_time = "{:04d}-{:02d}-{:02d}%20{:02d}:{:02d}:{:02d}".format(t[0], t[1], t[2], t[3], t[4], t[5])
            pathway_string = "WiFi-{}".format(target_ssid.replace(" ", ""))
                       
            full_url = "{}{}".format(HOME_GATEWAY, PARAMS.format(
                boat_name, pathway_string, boat_status, battery_v, latitude, longitude,
                temperature, humidity, f_lat, f_lng, f_rad, 
                uptime_sec, success_logs, failed_logs, modemTemperature, pressure, url_time
            ))            
            
            log_msg("DEBUG", "Sending URL: {}".format(full_url), level="INFO")            
            
            try:
                # --- FIX: STRICT SOCKET CLOSURE ---
                req_headers = {'Connection': 'close'}
                response = urequests.get(full_url, headers=req_headers, timeout=25)
                
                if response.status_code == 200:
                    log_msg("WIFI","Server Response Code: 200 (SUCCESS)", level="INFO")
                    success_logs += 1  
                    resp_text = response.text
                    response.close()
                    
                    process_server_handshake(resp_text)
                    process_backlog_on_wifi()
                    
                    upload_success = True
                    break 
                else:
                    log_msg("WIFI","Server Response Code: {}".format(response.status_code), level="WARNING")
                
                response.close()
            except Exception as e:
                log_msg("WIFI","Session payload drop error on '{}': {}".format(target_ssid, e), level="ERROR")
            
            try: wlan.disconnect()
            except: pass
            time.sleep(1)
        else:
            log_msg("WIFI","Failed to connect to '{}' after {} attempts (TIMEOUT).".format(target_ssid, attempts_made), level="WARNING")
    
    wlan.active(False) 
    gc.collect()
    safe_set_freq(workingSpeed)
    return upload_success

def check_cellular_signal_quality():
    """Queries the modem for signal strength and quality."""
    res = talk("AT+CSQ")
    if "+CSQ:" in res:
        # Extract just the comma-separated values, ignoring the newlines and 'OK'
        csq_val = res.split("+CSQ:")[1].splitlines()[0].strip()
        log_msg("CELL","Signal Quality: {}".format(csq_val), level="INFO")
        return True, csq_val
    return False, "UNKNOWN"

# --- DYNAMICALLY RESOLVED STRATEGY 2: CELLULAR ROUTINE ---
def run_cellular_pathway(f_lat, f_lng, f_rad):
    global success_logs
    log_msg("CELL","TRYING CELLULAR FALLBACK ROUTE","INFO")
    
    # 1. Clear out any lingering socket layers safely
    talk("AT+HTTPTERM")
    talk("AT+CIPCLOSE=0")
    talk("AT+NETCLOSE")
    
    # --- FIX: 30-SECOND SMART SETTLEMENT LOOP ---
    log_msg("CELL","Waiting for network settlement...", level="INFO")
    net_ready = False
    
    for _ in range(60):  
        reg_check = talk("AT+CGREG?", wait=500)
        if ",1" in reg_check or ",5" in reg_check: 
            net_ready = True
            break
            
    if not net_ready:
        return "NO_NETWORK_REG"
        
    cops_reply = talk("AT+COPS?")
    provider = "4G" 
    
    if '"' in cops_reply:
        try:
            raw_code = cops_reply.split('"')[1]
            if raw_code in ["23430", "23433", "23434"]: provider = "EE"
            elif raw_code in ["23410", "23402", "23411"]: provider = "O2"
            elif raw_code in ["23415", "23491"]:          provider = "Voda"
            elif raw_code in ["23420", "23486"]:          provider = "Three"
            else: provider = "Roam" 
        except:
            provider = "4G"

    if CELL_MULTIPLIERS[cell_tier_index] > 0 and cell_cycles_skipped > 0:
        pathway_string = "4g-{}_SuspNetblock*{}".format(provider, cell_tier_index)
    else:
        pathway_string = "4g-{}".format(provider)
        
    log_msg("CELL","Active Network Link Identified: {}".format(pathway_string), level="INFO")       
        
    # 2. Configure and activate the context plane
    talk('AT+CGDCONT=1,"IP","' + APN + '"')
    talk("AT+CGACT=1,1")
    talk("AT+CIPTIMEOUT=30000,30000,30000") 
    
    csq_ok, current_csq = check_cellular_signal_quality()
        
    # 3. Open the main internet access pipeline
    net_res = talk("AT+NETOPEN", wait=2000)    
    if "OK" not in net_res and "+NETOPEN: 0" not in net_res: 
        log_msg("CELL","NETOPEN Failed. CSQ: {}".format(current_csq), level="ERROR")
        return "NETOPEN_FAIL"
        
    # 4. Initiate connection request and capture initial response window
    talk_res = talk('AT+CIPOPEN=0,"TCP","{}",80'.format(TUNNEL_HOST), wait=1000)
    
    if "ERROR" in talk_res:
        log_msg("CELL","CIPOPEN Error: Modem rejected connection attempt. CSQ: {}".format(current_csq), level="ERROR")
        talk("AT+NETCLOSE")
        return "MODEM_REJECTED"
        
    connected = False
    open_urc = ""
    
    if "+CIPOPEN: 0,0" in talk_res:
        connected = True
    elif "+CIPOPEN: 0," in talk_res:
        err_code = talk_res.split("0,")[1].split("|")[0].strip()
        log_msg("CELL","CIPOPEN Failed with Code: {}. CSQ: {}".format(err_code, current_csq), level="ERROR")
        talk("AT+CIPCLOSE=0")
        talk("AT+NETCLOSE")
        return "CIPOPEN_ERR_" + str(err_code)

    if not connected:
        start_time = time.time()
        log_msg("CELL","Establishing remote handshake over cell towers... ", level="INFO")
        while (time.time() - start_time) < 15:  
            if uart.any():
                chunk = uart.read()
                if chunk:
                    try: open_urc += chunk.decode('utf-8')
                    except:
                        for byte in chunk:
                            if 32 <= byte <= 126 or byte in (10, 13): open_urc += chr(byte)
            
            if "+CIPOPEN: 0,0" in open_urc:
                connected = True
                break
            elif "+CIPOPEN: 0," in open_urc:
                break
            time.sleep_ms(100)
            
        if not connected:
            clean_urc = " | ".join([line.strip() for line in open_urc.splitlines() if line.strip()])
            log_msg("CELL","Remote link refused or timed out. URC: {} | CSQ: {}".format(clean_urc if clean_urc else "TIMEOUT", current_csq), level="ERROR")
            talk("AT+CIPCLOSE=0")
            talk("AT+NETCLOSE")
            return "HANDSHAKE_TIMEOUT"
            
    log_msg("CELL","Socket connected successfully (+CIPOPEN: 0,0)", level="INFO")
        
    # 5. Synthesize standard HTTP/1.1 payload
    uptime_sec = get_uptime_safely()
    t = time.localtime()
    url_time = "{:04d}-{:02d}-{:02d}%20{:02d}:{:02d}:{:02d}".format(t[0], t[1], t[2], t[3], t[4], t[5])
    
    param_string = PARAMS.format(
        boat_name, pathway_string, boat_status, battery_v, latitude, longitude,
        temperature, humidity, f_lat, f_lng, f_rad, 
        uptime_sec, success_logs, failed_logs, modemTemperature, pressure, url_time
    )
        
    http_packet = (
        "GET /boatLogger/log.php{} HTTP/1.1\r\n"
        "Host: {}\r\n"
        "User-Agent: Mozilla/5.0 (A7670E; MarineLogger)\r\n"
        "Accept: */*\r\n"
        "Connection: close\r\n\r\n"
    ).format(param_string, TUNNEL_HOST)
    
    # 6. Verify data entry prompt sequence is granted
    send_prompt = talk("AT+CIPSEND=0,{}".format(len(http_packet)), wait=500)
    if ">" not in send_prompt:
        log_msg("CELL","Modem rejected transaction entry allocation. CSQ: {}".format(current_csq), level="ERROR")
        talk("AT+CIPCLOSE=0")
        talk("AT+NETCLOSE")
        return "SEND_PROMPT_FAIL"
        
    uart.write(http_packet)
    time.sleep(3)
    
    # 7. Collect incoming server stream
    server_response = ""
    while uart.any():
        chunk = uart.read()
        if chunk:
            try: server_response += chunk.decode('utf-8')
            except:
                for byte in chunk:
                    if 32 <= byte <= 126 or byte in (10, 13): server_response += chr(byte)
        time.sleep_ms(50)
        
    log_msg("CELL","Received {} bytes from UART stream.".format(len(server_response)), level="DEBUG")
    
    if server_response.startswith(http_packet):
        network_reply = server_response[len(http_packet):].strip()
    else:
        network_reply = server_response.strip()
        
    log_msg("CELL","Raw Network Reply: {}".format(network_reply.replace("\r\n", " | ")), level="DEBUG")
    
    success = "200 OK" in server_response or "HTTP/1.1 200" in server_response or '"commands":' in server_response
    
    if success: 
        success_logs += 1  
        process_server_handshake(server_response)
    
    talk("AT+CIPCLOSE=0")
    talk("AT+NETCLOSE")
    
    return "SUCCESS" if success else "SERVER_NO_RESPONSE"

def process_server_handshake(http_body_string):
    global force_immediate_cycle, force_anchor_snap
    try:
        start_idx = http_body_string.find("{")
        end_idx = http_body_string.rfind("}") + 1
        if start_idx != -1 and end_idx != -1:
            server_data = json.loads(http_body_string[start_idx:end_idx])
            if "commands" in server_data:
                config = load_config()
                cmds = server_data["commands"]
                has_changed = False
                
                # Default to 0 if the timestamps don't exist yet
                server_timestamp = cmds.get("updated_at_unix", 0) 
                local_timestamp = config["system"].get("local_updated_at", 0)

                # --- THE FIX: ALL COMMANDS ARE NOW PROTECTED BY THIS BLOCK ---
                if server_timestamp >= local_timestamp:
                    
                    # 1. Update Status
                    if "current_status" in cmds and config["system"]["current_status"] != cmds["current_status"]:
                        config["system"]["current_status"] = cmds["current_status"]
                        log_msg("SYSTEM","Remote override authorized. State changed to: {}".format(cmds["current_status"]), level="INFO")
                        has_changed = True
                        
                    # 2. Update Coordinates (Unless waiting for a physical snap!)
                    if not force_anchor_snap:
                        profile = "mooring" if config["system"]["current_status"] == "moored" else "anchor"
                        for key, cmd_key in [("lat", "anchor_lat"), ("lng", "anchor_lng"), ("geofence_meters", "geofence_meters")]:
                            if cmd_key in cmds and abs(config[profile][key] - float(cmds[cmd_key])) > 0.00001:
                                config[profile][key] = float(cmds[cmd_key])
                                has_changed = True
                    else:
                        log_msg("SYSTEM","Remote coordinates bypassed pending local GPS snap.", level="WARNING")
                            
                    # 3. Update Timers
                    if "mooring_sleep_sec" in cmds and config["mooring"]["sleep_sec"] != int(cmds["mooring_sleep_sec"]):
                        config["mooring"]["sleep_sec"] = int(cmds["mooring_sleep_sec"])
                        has_changed = True
                    if "anchor_sleep_sec" in cmds and config["anchor"]["sleep_sec"] != int(cmds["anchor_sleep_sec"]):
                        config["anchor"]["sleep_sec"] = int(cmds["anchor_sleep_sec"])
                        has_changed = True
                    if "travelling_sleep_sec" in cmds and config["travelling"]["sleep_sec"] != int(cmds["travelling_sleep_sec"]):
                        config["travelling"]["sleep_sec"] = int(cmds["travelling_sleep_sec"])
                        has_changed = True

                    if has_changed:
                        # CRITICAL: Fast-forward the local time so we don't process this exact command again
                        config["system"]["local_updated_at"] = server_timestamp
                        save_config(config)
                        force_immediate_cycle = True
                        log_msg("SYSTEM","Dynamic target settings synchronized.", level="INFO")
                
                else:
                    # If the local switch was flipped more recently, ignore the entire server buffer!
                    log_msg("SYSTEM","Server override ignored. Local switch is newer. (Server: {}, Local: {})".format(server_timestamp, local_timestamp), level="INFO")
                    
    except Exception as e:
        log_msg("SYSTEM","Server downlink parsing bypassed.", level="WARNING")
        
       
def get_standalone_gps_lock(last_state):
    #"""Getting GPS lock: GNSS on, Cellular off. Relies on sky broadcast."""
    log_msg("GNSS","Executing Standalone Cold/Warm Start Sequence...", level="INFO")
    fix_acquired = False
    
    for attempt in range(1, 16):
        if read_physical_toggle_switch() != last_state: return False
        info = talk("AT+CGNSSINFO", wait=500)
        if "ERROR" in info: talk("AT+CGNSSPWR=1", wait=1000); continue
        if "+CGNSSINFO:" in info and ",,,," not in info:
            if parse_gnss_string(info): return True
        flash_recovery_pattern(); time.sleep(1)
        
    log_msg("GNSS","Quick lock failed. Escalating to Secondary Deep Hunting Loop...", level="WARNING")
    deep_search_start = time.time()
    
    while (time.time() - deep_search_start) < deepSearchSec:
        if read_physical_toggle_switch() != last_state: return False
        info = talk("AT+CGNSSINFO", wait=3000) 
        if "ERROR" in info: talk("AT+CGNSSPWR=1", wait=2000); continue
        if "+CGNSSINFO:" in info and ",,,," not in info:
            if parse_gnss_string(info): return True
        flash_recovery_pattern(); time.sleep(3)
        
    return False


def get_agps_lock(last_state):
    """Benchboat's Test: Cellular on. Uses 4G to download Ephemeris."""
    log_msg("GNSS","Executing Cellular Assisted-GPS (A-GPS) Sequence...", level="INFO")
    
    # 1. Wake the cellular modem and wait for network attachment
    talk("AT+CFUN=1")
    net_ready = False
    log_msg("GNSS","Waiting for Cellular Network attachment for A-GPS...", level="INFO")
    for _ in range(15):
        if read_physical_toggle_switch() != last_state: return False
        reg = talk("AT+CGREG?", wait=1000)
        if ",1" in reg or ",5" in reg:
            net_ready = True
            break
        time.sleep(1)
        
    if not net_ready:
        log_msg("GNSS","Cellular attachment failed. A-GPS cannot download almanac.", level="WARNING")
        return False
        
    # 2. Activate the Data Tunnel (PDP Context)
    talk('AT+CGDCONT=1,"IP","infisim.iot"')
    talk("AT+CGACT=1,1")
    
    # 3. Poll for the lock (The internal firmware handles the SUPL download automatically)
    log_msg("GNSS","Network ready. Requesting accelerated lock...", level="INFO")
    search_start = time.time()
    
    # Give it up to 3 minutes, though A-GPS usually locks in under 15 seconds
    while (time.time() - search_start) < 180:
        if read_physical_toggle_switch() != last_state: return False
        info = talk("AT+CGNSSINFO", wait=2000) 
        if "ERROR" in info: talk("AT+CGNSSPWR=1", wait=1000); continue
        if "+CGNSSINFO:" in info and ",,,," not in info:
            if parse_gnss_string(info): return True
        flash_recovery_pattern(); time.sleep(1)
        
    return False


# EMERGENCY SHIELD: The "Pete Tong" Limit
if boot_count > MAX_REBOOTS:
    # We are in a crash loop. Stop everything to save battery.
    log_msg("SYSTEM","Reboot loop detected. Entering safe mode.", level="CRITICAL")
    while True:
        # Rapid blink LED to indicate error state
        machine.Pin(LED_PIN, machine.Pin.OUT).value(not machine.Pin(LED_PIN, machine.Pin.OUT).value())
        time.sleep(0.5)


# Update RTC with current state
save_state_to_rtc()


try:
    global_wlan = network.WLAN(network.STA_IF)
    global_wlan.active(False)
    global_ap = network.WLAN(network.AP_IF)
    global_ap.active(False)
    log_msg("WIFI","✅ Wi-Fi DMA Memory Successfully Reserved.","INFO")
except Exception as e:
    log_msg("WIFI","❌ Wi-Fi Allocation Failed:", level="ERROR")

last_loop_ticks = time.ticks_ms()


# Create a global flag
sd_ready = False

# try:
#     import sdcard 
#     spi = SPI(1, baudrate=100000, sck=Pin(SD_SCK), mosi=Pin(SD_MOSI), miso=Pin(SD_MISO))
#     sd = sdcard.SDCard(spi, Pin(SD_CS))
#     os.mount(sd, '/sd')
#     sd_ready = True
#     print("✅ MicroSD Card Mounted Successfully to '/sd'")
# except Exception as e:
#     print("❌ MicroSD Card Mount Failed:", e)
#     # sd_ready remains False


# Your home router network name
#HOME_SSID = "YOUR_HOME_WIFI_SSID"


# Unified DuckDNS path used when the board connects to Wi-Fi
HOME_GATEWAY = "http://YOUR_DOMAIN_OR_IP/boatLogger/log.php"

# DuckDNS host domain used out on the water over cell towers
TUNNEL_HOST = "YOUR_DOMAIN_OR_IP"

# --- FLEXIBLE CELLULAR NETBLOCK ADAPTATION CONFIG ---
CELL_MULTIPLIERS = [0, 1, 3, 6]  # Number of wake cycles to skip per penalty tier
cell_tier_index = 0              # Current position in the penalty array
cell_cycles_skipped = 0          # Tracker counting how many cycles have been skipped


# Global memory runtime registers (Seeded with hardcoded bootswitches)
#LOG_DEST = "logfile"
LOG_DEST = "terminal"

#print("past LOG_DEST")


# --- POWER & ACQUISITION ARCHITECTURE FLAGS ---

# In the 7670E the ephemeris data was lost during deep sleep, so instead adopted a lower power throttling approach which maintained power to the GPS memory.
# Subsequently, found AGPS which uses cellular to assist in recovery of ephemeris data more quickly. However, this is a risk when signal availability is low.
#
# The actual boot-up, lock, geofence update and logging takes longer with the AGPS + deepSleep mode, so have switched back. Also, deepSleeping during a geofence
# breach shuts off the asynchronous alarm.

sleepMode = "cpuThrottling" # Options: "cpuThrottling" (Standard) or "deepSleep" (Maximum Power Save)
useAGPS = False  # Options: False (Standalone Sky Broadcast), True (Cellular Assisted-GPS)

#sleepMode = "deepSleep"  # deepSleep seems to rest at 1.2w, uptime is brief, 5sec with AGPS on.
#useAGPS = True

deepSearchSec = 60*30

boat_status = "unknown"
battery_v   = 12.0  
latitude    = 0.00000   
longitude   = 0.00000
modemTemperature = None   
temperature      = None   
humidity         = None
pressure         = None

# Manipulate cellular/wifi behaviour for debug purposes
useCellular = True


sleepGPSduringlowpwr = False

alarm_counter = 0
last_physical_state = "unknown"
force_anchor_snap = False
force_immediate_cycle = False

# --- EDGE DIAGNOSTIC MEMORY COUNTERS ---
# success_logs and failed_logs are now natively loaded from RTC memory
boot_ticks = time.ticks_ms()  # Establishes exact startup point

# Expanded query payload structure to accept uptime, success, and failure registers
PARAMS = "?key=" + API_KEY + "&device_id={}&link={}&status={}&v={}&lat={}&lng={}&temp={}&hum={}&flat={}&flng={}&frad={}&uptime={}&success={}&fail={}&m_temp={}&pressure={}&time={}"

# Initialize Hardware Peripherals with expanded 2KB RX buffer to prevent memory truncation
uart = machine.UART(1, baudrate=115200, tx=UART_TX, rx=UART_RX, timeout=2000, rxbuf=2048)

# Initialize SHT40 I2C Interface (Pins verified working for Option B Grove standard)
# Yellow -> SDA (GPIO 22), White -> SCL (GPIO 21)
i2c = machine.I2C(0, scl=machine.Pin(SCLpin), sda=machine.Pin(SDApin), freq=100000)

led = machine.Pin(LED_PIN, machine.Pin.OUT)
buzzer = machine.Pin(BUZZER_PIN, machine.Pin.OUT)
gps_en = machine.Pin(GPS_EN_PIN, machine.Pin.OUT)
pwr_rail = machine.Pin(BOARD_PWR_PIN, machine.Pin.OUT)

sw_moored = machine.Pin(SWITCH_MOORED_PIN, machine.Pin.IN)
sw_anchored = machine.Pin(SWITCH_ANCHORED_PIN, machine.Pin.IN)

adc = machine.ADC(machine.Pin(BATTERY_ADC_PIN))
adc.atten(machine.ADC.ATTN_11DB)
                                                                   


# Cold Boot Initialization Sequence (rxbuf=2048 added to enforce the memory space shield)
log_msg("      ", "===========================================================================================", level="INFO")
log_msg("SYSTEM", "=== Cold Boot Complete. Entering Main (While True:) Loop. Active State Engaging ===","INFO")
log_msg("      ", "===========================================================================================", level="INFO")

safe_set_freq(workingSpeed) # machine.freq
#uart.init(baudrate=115200, tx=UART_TX, rx=UART_RX, timeout=2000, rxbuf=2048)

play_tune("reboot")


# Load into the correct variable name so the rest of the loop can see it
boot_config = load_config()
current_mode = boot_config["system"]["current_status"]
profile = "mooring" if current_mode == "moored" else "anchor"

# Apply the loaded settings to your system paths
LOG_DEST  = boot_config["system"]["log_dest"]
latitude  = boot_config[profile].get("lat", 0.00000)
longitude = boot_config[profile].get("lng", 0.00000)

last_physical_state = read_physical_toggle_switch()
log_msg("HARDWARE","Battery Input Voltage: {}V | Switch: {}".format(read_logger_voltage(), last_physical_state.upper()), level="INFO")

pwr_rail.value(1); gps_en.value(1); led.value(1); time.sleep(1)
for _ in range(3): uart.write("AT\r\n"); time.sleep_ms(100)

modem_awake = False
for _ in range(5):
    if "OK" in talk("AT", wait=500): modem_awake = True; break
if not modem_awake:
    pwrkey = machine.Pin(MODEM_PWRKEY, machine.Pin.OUT)
    pwrkey.value(0); time.sleep_ms(100); pwrkey.value(1); time.sleep(1.5); pwrkey.value(0); time.sleep(6)

# ---> Add the APN string injection RIGHT HERE
talk('AT+CGDCONT=1,"IP","' + APN + '"', wait=300) 
talk("AT+CFUN=1")


# TEST SMS message code
#
# # --- WAIT FOR SMS/VOICE REGISTRATION BEFORE SMS ---
# log_msg("  [SYSTEM] Waiting for cell tower registration...", level="INFO")
# net_ready = False
# for _ in range(30): # Increased to 30 seconds for roaming CS fallback
#     reg = talk("AT+CREG?", wait=1000) # CREG checks SMS readiness, CGREG checks Data
#     if ",1" in reg or ",5" in reg:
#         net_ready = True
#         break
#     time.sleep(1)
# 
# if net_ready:
#     config = load_config()
#     phone_num = config.get("alerts", {}).get("mobile_number", "")                       
#     msg = "=test= ANCHOR ALARM: Vessel drifting! =test="
#     send_sms(phone_num, msg)
# else:
#     log_msg("  [SMS] Aborted test SMS. Failed to register on network.", level="WARNING")
# 
# phone_num = config.get("alerts", {}).get("mobile_number", "")                       
# msg = "=test= ANCHOR ALARM: Vessel drifting! =test="
# send_sms(phone_num, msg)

# --- HARDWARE SPECIFIC GNSS POWER ROUTING ---
if hardware_version == "v2":
    log_msg("HARDWARE","V2 detected: Activating GPS Antenna LDO via Modem GPIO 4...", level="INFO")
    # 1. Set Modem internal GPIO 4 to Output direction
    talk("AT+CGDRT=4,1", wait=200)
    # 2. Pull Modem internal GPIO 4 High to power the active antenna
    talk("AT+CGSETV=4,1", wait=500) 
    # 3. Enable all global satellite constellations (GPS+GLONASS+GALILEO+BEIDOU)
    talk("AT+CGNSSMODE=15", wait=200)
# --------------------------------------------

talk("AT+CGNSSPWR=1")

wifiSuccessUploads = 0

while True:
    try:
    
        safe_set_freq(workingSpeed)
        #uart.init(baudrate=115200, tx=UART_TX, rx=UART_RX, timeout=2000, rxbuf=2048)
        
        check_log_size_limit()
        
        # --- NEW: HARDWARE WAKE-UP SEQUENCE ---
        if sleepGPSduringlowpwr:
            gps_en.value(1)               # Restore physical power to GPS
        talk("AT", wait=200)          # Dummy command to wake modem over UART
        talk("AT+CSCLK=0", wait=200)  # Disable modem sleep mode
        
        # --- RE-ASSERT ANTENNA POWER ---
        # CFUN=0 at the end of the last loop may have reset the modem's GPIOs!
        if hardware_version == "v2":
            talk("AT+CGDRT=4,1", wait=200)
            talk("AT+CGSETV=4,1", wait=500)    
        
        talk("AT+CGNSSPWR=1", wait=500) # Turn the GNSS decoder back on
        # --------------------------------------    
        
        while uart.any(): uart.read()
        
        battery_v = read_logger_voltage()
        physical_switch = read_physical_toggle_switch()
        config = load_config()
        
        # --- PHYSICAL SWITCH GESTURE DETECTOR BLOCK ---
        if physical_switch != last_physical_state:
            if last_physical_state == "unknown":
                config["system"]["current_status"] = physical_switch
                config["system"]["local_updated_at"] = time.time()
                save_config(config)
                last_physical_state = physical_switch
            else:
                # 1. IMMEDIATE VISUAL CUE: Start flashing BEFORE we even print the log
                # This makes the "Invite" start the millisecond the switch is flipped
                window_start = time.ticks_ms()
                gesture_triggered = False
                
                log_msg("SWITCH","State shift caught. Opening 3-second Wiggle Invite Window...", level="INFO")
                
                # 2. HIGH-INTENSITY WIGGLE WINDOW
                while time.ticks_diff(time.ticks_ms(), window_start) < 3000:
                    # Fast strobe: 75ms ON / 75ms OFF
                    led.value(1); time.sleep_ms(75)
                    
                    # Check for wiggle (Switch moved back to last_physical_state)
                    if read_physical_toggle_switch() == last_physical_state:
                        gesture_triggered = True
                        led.value(0) 
                        break
                    
                    led.value(0); time.sleep_ms(75)
                
                # 3. Handle Result
                if gesture_triggered:
                    log_msg("SWITCH","Dynamic wiggle signature validated! Launching emergency portal.")
                    silence_alarm()
                    alarm_counter = 0
                    run_emergency_portal(duration_sec=300)
                    last_physical_state = read_physical_toggle_switch() 
                else:
                    log_msg("SWITCH","Invitation expired. Migrating tracking baseline. Silencing active alarms.")
                    silence_alarm()
                    alarm_counter = 0
                    config["system"]["current_status"] = physical_switch
                    config["system"]["local_updated_at"] = time.time() 
                    if physical_switch == "anchored": force_anchor_snap = True
                    save_config(config)
                    last_physical_state = physical_switch
        
            
        LOG_DEST = config["system"]["log_dest"]
        current_mode = config["system"]["current_status"]
        
        log_msg("SYSTEM", "=== ROUTINE LOGGING SEQUENCE START ===", level="INFO")
        
        if current_mode == "moored":
            target_lat, target_lng = config["mooring"]["lat"], config["mooring"]["lng"]
            fence_radius, current_sleep_sec = config["mooring"]["geofence_meters"], config["mooring"]["sleep_sec"]
        elif current_mode == "anchored":
            target_lat, target_lng = config["anchor"]["lat"], config["anchor"]["lng"]
            fence_radius, current_sleep_sec = config["anchor"]["geofence_meters"], config["anchor"]["sleep_sec"]
        else:
            target_lat, target_lng, fence_radius, current_sleep_sec = 0.0, 0.0, 0.0, config["travelling"]["sleep_sec"]

        talk('AT+CGDCONT=1,"IP","' + APN + '"', wait=300)
        talk("AT+CFUN=1", wait=1000); led.value(1)
        
# PHASE 1: ACQUIRING VECTORS FROM LIVE STREAM
        # FIX: Use the monotonic millisecond counter instead of the RTC wall-clock
        lock_start_ticks = time.ticks_ms()
        
        log_msg("SYSTEM","GPS lock sequence initiated...", level="INFO")
        
        fix_acquired = False
        
        if useAGPS:
            fix_acquired = get_agps_lock(last_physical_state)
            
            # --- THE FAIL-SAFE FALLBACK ---
            if not fix_acquired and read_physical_toggle_switch() == last_physical_state:
                log_msg("GNSS","A-GPS failed or unavailable. Falling back to Standalone mode...", level="WARNING")
                fix_acquired = get_standalone_gps_lock(last_physical_state)
        else:
            fix_acquired = get_standalone_gps_lock(last_physical_state)
            
        # FIX: Calculate the difference safely handling internal counter rollovers
        lock_duration_ms = time.ticks_diff(time.ticks_ms(), lock_start_ticks)
        lock_duration = lock_duration_ms / 1000.0  # Convert milliseconds to seconds
        
        log_msg("SYSTEM","GPS lock attempt ended. Took {} seconds!".format(lock_duration), level="INFO")         
        
        # --- THE DEATH-LOOP PREVENTION SHIELD ---
        if sleepMode == "deepSleep" and lock_duration > 120:
            log_msg("SYSTEM","Deep Sleep would cause a battery death-loop. Downgrading to CPU Throttling.", level="ERROR")
            sleepMode = "cpuThrottling"
            
        if read_physical_toggle_switch() != last_physical_state:
            buzzer.value(0); talk("AT+CFUN=0", wait=500); continue


    # PHASE 2: PERIMETER SAFETY CHECK
        if fix_acquired:
            if current_mode == "anchored" and force_anchor_snap:
                log_msg("SYSTEM","Local Anchor Drop. Snapping geofence to current fix: {}, {}".format(latitude, longitude), level="INFO")
                config["anchor"]["lat"] = latitude
                config["anchor"]["lng"] = longitude
                save_config(config)
                target_lat, target_lng = latitude, longitude
                force_anchor_snap = False 
                
            if fence_radius > 0.0:
                drift_distance = calculate_drift(target_lat, target_lng, latitude, longitude)
                
                if drift_distance > fence_radius:
                    boat_status = "alarm"
                    current_sleep_sec = 15
                    
                    # Fire the siren ONLY on the first cycle of the breach
                    if alarm_counter == 0: 
                        trigger_anchor_alarm(duration_seconds=60) 
                        
                    alarm_counter += 1
                    
                    trigger_cycle = int(config.get("alerts", {}).get("sms_alarm_trigger_cycle", 2))
                    log_msg("ALARM","Geofence breached! Drift: {}m (Cycle {}/{})".format(int(drift_distance), alarm_counter, trigger_cycle), level="WARNING")
                    
                    if config.get("alerts", {}).get("sms_on_out_of_bounds", True):
                        phone_num = config.get("alerts", {}).get("mobile_number", "")
                        if alarm_counter == trigger_cycle:
                            msg = "ANCHOR ALARM: Vessel drifting! Distance: {}m. Mode: {}. Lat: {}, Lng: {}".format(
                                int(drift_distance), current_mode.upper(), latitude, longitude)
                            send_sms(phone_num, msg)
                        elif alarm_counter > trigger_cycle and (alarm_counter % 20 == 0):
                            msg = "ANCHOR ALARM UPDATE: Vessel still outside geofence. Drift: {}m. Lat: {}, Lng: {}".format(
                                int(drift_distance), latitude, longitude)
                            send_sms(phone_num, msg)
                            
                else: 
                    # --- INNER ELSE: Vessel is still inside the safe geofence radius ---
                    boat_status = current_mode
                    if alarm_counter > 0:
                        log_msg("ALARM","Vessel returned to safe perimeter. Silencing siren and resetting.", level="INFO")
                        silence_alarm()
                    alarm_counter = 0 
                    
            else: 
                # --- MIDDLE ELSE: fence_radius is 0.0 (e.g., Travelling Mode) ---
                boat_status = current_mode
                if alarm_counter > 0:
                    log_msg("ALARM","Tracking mode disabled geofence. Silencing siren and resetting.", level="INFO")
                    silence_alarm()
                alarm_counter = 0
                
        else: 
            # --- OUTER ELSE: The system failed to acquire a GPS fix ---
            boat_status = "offline"


        if read_physical_toggle_switch() != last_physical_state:
            buzzer.value(0); talk("AT+CFUN=0", wait=500); continue

        # Phase 5: Environment metrics recorded before communication of these values to the web.
        # Capture live ambient cabin environmental metrics with a 3-try safety limit
        if current_mode != "offline":
            for attempt in range(3):
                if read_modem_temperature():
                    break
                time.sleep_ms(200)    
        for attempt in range(3):
            if read_cabin_climate(): break
            time.sleep_ms(200) # Brief pause to let electrical noise settle
            
        for attempt in range(3):
            if read_barometric_pressure(): break
            time.sleep_ms(200)    


        # PHASE 4: RUN SECURE COMMUNICATION ROUTINES
        upload_success = False
        was_cellular_cycle = False  
        
        # 1. ALWAYS attempt Wi-Fi first (20% of the time based on your dice roll rule)
        if wifiSuccessUploads <= failWIFIeveryNthGo:
            upload_success = try_wifi_pathway(target_lat, target_lng, fence_radius)
            if upload_success:
                log_msg("SYSTEM","Upload successful via WIFI.", level="INFO")
                wifiSuccessUploads = wifiSuccessUploads + 1

        else:
            log_msg("SYSTEM","Skipped Wi-Fi path (failWIFIeveryNthGo rule). Forcing Cellular pathway evaluation.", level="INFO")
            wifiSuccessUploads = 0

        # 2. If Wi-Fi failed (or was skipped), evaluate the Cellular Netblock Multiplier Array
        if not upload_success and useCellular: 
            raw_skips = CELL_MULTIPLIERS[cell_tier_index]
            required_skips = raw_skips
            
            # --- NEW: ADAPTIVE PENALTY CAP ---
            # Cap the maximum allowed penalty time to 45 minutes (2700 seconds).
            # If a long sleep interval pushes the penalty over 45 minutes, compress the multiplier.
            MAX_OFFLINE_SECONDS = 2700 
            
            if raw_skips > 0:
                if (current_sleep_sec * raw_skips) > MAX_OFFLINE_SECONDS:
                    # Calculate how many cycles can actually fit inside the maximum allowed offline window
                    required_skips = max(1, int(MAX_OFFLINE_SECONDS / current_sleep_sec))
                    log_msg("SYSTEM","Multiplier compressed from {} to {} skips to honor {}s max-offline cap.".format(raw_skips, required_skips, MAX_OFFLINE_SECONDS), level="INFO")

            if cell_cycles_skipped < required_skips:
                cell_cycles_skipped += 1
                # Maintain tracking metrics for remote database logs
                was_cellular_cycle = False # Don't apply jitter to skipped cycles            
                # Identify current carrier to keep logs descriptive
                cops_reply = talk("AT+COPS?")
                provider = "4G" # Fallback tag

                if '"' in cops_reply:
                    try:
                        raw_code = cops_reply.split('"')[1]
                        # Map standard UK Mobile Network Codes (MNC)
                        if raw_code in ["23430", "23433", "23434"]: provider = "EE"
                        elif raw_code in ["23410", "23402", "23411"]: provider = "O2"
                        elif raw_code in ["23415", "23491"]:          provider = "Voda"
                        elif raw_code in ["23420", "23486"]:          provider = "Three"
                        else: provider = "Roam" # Non-standard or cross-roaming gateway
                    except:
                        provider = "4G"            
                
                log_msg("SYSTEM","4g-{}_SuspNetblock*{} -> Skip cycle {} of {}.".format(provider, cell_tier_index, cell_cycles_skipped, required_skips), level="INFO")
            else:
                # We have served our penalty skips! Safe to attempt a live cellular upload
                was_cellular_cycle = True
                cellular_result = run_cellular_pathway(target_lat, target_lng, fence_radius)
                
                if cellular_result == "SUCCESS":
                    # Genuine Success: Clear all penalty indexes completely
                    upload_success = True
                    cell_tier_index = 0
                    cell_cycles_skipped = 0
                    log_msg("SYSTEM","Cellular connection verified clear. Resetting penalty tiers.", level="INFO")
                    
                elif cellular_result in ["MODEM_REJECTED", "NETOPEN_FAIL", "NO_NETWORK_REG"]:
                    # Hardware / Local Rejection: The modem itself is confused. 
                    # Do NOT escalate the penalty tier, as this is not a server blocking issue.
                    upload_success = False
                    cell_cycles_skipped = 0 
                    log_msg("SYSTEM","Local hardware/modem rejection ({}). Preserving current penalty tier.".format(cellular_result), level="WARNING")
                    
                else:
                    # Cycle Failed (Timeout, Refused, Server No Response):
                    # The network is up, but the server handshake failed. Escalate the penalty tier.
                    upload_success = False
                    cell_cycles_skipped = 0 
                    cell_tier_index = min(cell_tier_index + 1, len(CELL_MULTIPLIERS) - 1)
                    log_msg("SYSTEM","Cellular netblock persistent ({}). Escalating to penalty tier index: {}".format(cellular_result, cell_tier_index), level="ERROR")
        
        
        led.value(0)
        if upload_success: 
            log_msg("SYSTEM","=== CYCLE SUCCESS - SERVER TRANSACTION COMPLETE ===", level="INFO")
            log_msg("INFO","Lat: {}, Lng: {} | Batt: {}V | Cabin: {}°C / {} hPa".format(latitude, longitude, battery_v, temperature, pressure), level="INFO")        
        else:
            failed_logs += 1  # Increment the log failure count
            log_msg("SYSTEM","=== CYCLE FAILURE DATA NOT UPLOADED ===", level="ERROR")
            log_msg("INFO","Last Known Lat: {}, Lng: {} | Batt: {}V | Cabin Temp: {}°C | Modem Temp: {}°C / {} hPa".format(latitude, longitude, battery_v, temperature, modemTemperature, pressure), level="ERROR")
            
            # --- NEW: TRIGGER LOCAL SAVE ON TOTAL FAILURE ---
            uptime_sec = get_uptime_safely()
            t = time.localtime()
            url_time = "{:04d}-{:02d}-{:02d}%20{:02d}:{:02d}:{:02d}".format(t[0], t[1], t[2], t[3], t[4], t[5])
            
            param_string = PARAMS.format(
                boat_name, "Offline_Stored", boat_status, battery_v, latitude, longitude,
                temperature, humidity, target_lat, target_lng, fence_radius, 
                uptime_sec, success_logs, failed_logs, modemTemperature, pressure, url_time
            )
            save_failed_log_locally(param_string)           
            play_tune("error")                                                                                           
            
        if force_immediate_cycle and upload_success: 
            current_sleep_sec = 1
            force_immediate_cycle = False

        # --- CALCULATE STANDBY TIMING PRESERVING JITTER RULES ---
        if was_cellular_cycle and current_sleep_sec > 15:
            # Generate random variation between -20 and +40 seconds
            jitter = random.randint(-20, 40)
            final_sleep_target = int(current_sleep_sec) + jitter
            log_msg("TIMER","Cellular path evaluated. Applied Jitter: {}s".format(jitter), level="INFO")
        else:
            # Strict execution timing for clean Wi-Fi links or fast alarm intervals
            final_sleep_target = int(current_sleep_sec)
            log_msg("TIMER","Wi-Fi or fast-cycle active. Standard timing enforced.", level="INFO")

        
        # --- NEW: TOTAL PERIPHERAL SHUTDOWN SEQUENCE ---
        if sleepGPSduringlowpwr:
            talk("AT+CGNSSPWR=0", wait=500) # Stop GPS data stream
            gps_en.value(0) # Physically cut power to the active GPS antenna    

        talk("AT+CFUN=0", wait=500)     # Detach from Cell Network
        talk("AT+CSCLK=0", wait=300)    # Allow modem CPU to sleep
        
        try:
            network.WLAN(network.STA_IF).active(False) # Catch rogue bypassed Wi-Fi
            network.WLAN(network.AP_IF).active(False)  # Catch lingering portal APs
        except: pass
        
        log_msg("TIMER","Shifted radios to standby. Core idling for {} seconds...".format(final_sleep_target), level="INFO")
        get_uptime_safely()

        gc.collect()
        
        # If we reached this point, the board successfully completed its cycle without crashing!        
        boot_count = 0
        save_state_to_rtc()        

        if sleepMode == "deepSleep":
            log_msg("SYSTEM","=== ENTERING HARDWARE DEEP SLEEP ===", level="INFO")
            kill_all_peripherals()
            
            # CRITICAL FAST-FORWARD: Account for the time the CPU will spend powered off
            total_uptime_seconds += final_sleep_target
            save_state_to_rtc()            
            
            # Deep sleep takes milliseconds
            machine.deepsleep(final_sleep_target * 1000) 
        else:
            time.sleep_ms(200) 
            safe_set_freq(slowestSpeed) # machine.freq
            #uart.init(baudrate=115200, tx=UART_TX, rx=UART_RX, timeout=2000, rxbuf=2048)
            
            sleep_intervals = final_sleep_target * 10
            for _ in range(sleep_intervals):
                if read_physical_toggle_switch() != last_physical_state: break
                time.sleep_ms(100)

    except Exception as e:
        # 1. Log the specific error
        try:
            # Use str(e) or sys.print_exception to know WHY it crashed
            log_msg("CRITICAL","it's all gone Pete Tong: {}".format(str(e)), level="CRITICAL")
            # 2. Safety Cleanup
            # If the modem is stuck mid-transmission, this kills it before the reboot
            kill_all_peripherals()
        except:
            pass
            
        # 3. Short cooldown
        # Prevent "rapid fire" reboot loops if a hardware component is physically broken
        time.sleep(5)
        
        # 4. Reboot
        #machine.reset()
