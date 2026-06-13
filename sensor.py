import requests
import json
from sense_emu import SenseHat
from time import sleep

sense = SenseHat()

# Define initial values
day = 1
month = 1
site_id = 1
selected_date = False
predicted_data_received = False
display_mode = "temperature"

# Mapping of site IDs to location names
site_locations = {
    1: "Wynyard",
    2: "Launceston",
    3: "Smithton",
    4: "Hobart",
    5: "Campania"
}

# Function to enter setup mode
def enter_setup_mode():
    global day, month, site_id, selected_date, predicted_data_received
    sense.show_message("Setup Mode", scroll_speed=0.05, text_colour=[0, 255, 0])
    mode = 0  # 0 for day, 1 for month, 2 for site ID
    while True:
        if mode == 0:
            sense.show_message(f"Day: {day}", scroll_speed=0.05)
        elif mode == 1:
            sense.show_message(f"Month: {month}", scroll_speed=0.05)
        else:
            sense.show_message(f"Site: {site_locations[site_id]}", scroll_speed=0.05)

        for event in sense.stick.get_events():
            if event.action == "pressed":
                if event.direction == "left":
                    mode = (mode - 1) % 3
                elif event.direction == "right":
                    mode = (mode + 1) % 3
                elif event.direction == "up":
                    if mode == 0:
                        day = (day % 31) + 1
                    elif mode == 1:
                        month = (month % 12) + 1
                    else:
                        site_id = (site_id % 5) + 1
                elif event.direction == "down":
                    if mode == 0:
                        day -= 1
                        if day < 1:
                            day = 31
                    elif mode == 1:
                        month -= 1
                        if month < 1:
                            month = 12
                    else:
                        site_id -= 1
                        if site_id < 1:
                            site_id = 5
                elif event.direction == "middle":
                    if mode == 2:
                        sense.show_message("Exit Setup Mode", scroll_speed=0.05, text_colour=[255, 0, 0])
                        sleep(1)
                        sense.clear()
                        selected_date = True
                        predicted_data_received = False
                        print(f"Selected Date: 2026-{month}-{day}")
                        return day, month, site_id

# Function to send data to server
def send_data_to_server(day, month, site_id):
    print("Sending request to server...")
    url = "http://iotserver.com/recordDeviceData.php"
    date_string = f"2026-{month}-{day}"
    params = {
        "date": date_string,
        "site_id": site_id
    }
    response = requests.get(url, params=params)
    print("Data sent to server successfully!")
    response_data = json.loads(response.text)
    print("Site:", response_data["site_name"])
    print("Date:", response_data["timestamp"])
    print("Minimum Temperature Prediction:", response_data["minTemperaturePrediction"])
    print("Maximum Temperature Prediction:", response_data["maxTemperaturePrediction"])
    print("Minimum Humidity Prediction:", response_data["minHumidityPrediction"])
    print("Maximum Humidity Prediction:", response_data["maxHumidityPrediction"])
    day = 1
    month = 1
    site_id = 1
    return response_data

# Main loop
while True:
    if not selected_date:
        for event in sense.stick.get_events():
            if event.action == "pressed" and event.direction == "middle":
                enter_setup_mode()
    else:
        if not predicted_data_received:
            response_data = send_data_to_server(day, month, site_id)
            predicted_data_received = True

        current_temperature = sense.get_temperature()
        current_humidity = sense.get_humidity()

        if display_mode == "temperature":
            if response_data["minTemperaturePrediction"] <= current_temperature <= response_data["maxTemperaturePrediction"]:
                sense.show_message(f"Temp: {current_temperature}C", scroll_speed=0.05, text_colour=[0, 0, 139], back_colour=[0, 255, 0])  # Green if within prediction
            else:
                sense.show_message(f"Temp: {current_temperature}C", scroll_speed=0.05, text_colour=[0, 0, 139], back_colour=[255, 0, 0])  # Red if lower than prediction
            sleep(1)
        elif display_mode == "humidity":
            if response_data["minHumidityPrediction"] <= current_humidity <= response_data["maxHumidityPrediction"]:
                sense.show_message(f"Humidity: {current_humidity}%", scroll_speed=0.05, text_colour=[0, 0, 139], back_colour=[0, 255, 0])  # Green if within prediction
            else:
                sense.show_message(f"Humidity: {current_humidity}%", scroll_speed=0.05, text_colour=[0, 0, 139], back_colour=[255, 0, 0])  # Red if lower than prediction
            sleep(1)

        for event in sense.stick.get_events():
            if event.action == "pressed":
                if event.direction == "left":
                    display_mode = "temperature"
                    sense.show_message("Temperature Mode", scroll_speed=0.05, text_colour=[255, 255, 0])  # Yellow color for temperature mode
                elif event.direction == "right":
                    display_mode = "humidity"
                    sense.show_message("Humidity Mode", scroll_speed=0.05, text_colour=[255, 255, 0])  # Yellow color for humidity mode
                elif event.direction == "middle":
                    enter_setup_mode()