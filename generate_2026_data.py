"""
Extends cleaned_training_data.csv (2015-2021) with synthetic 2022-2026 data.
Strategy: compute per-site per-month per-half-hour-slot averages from existing
data, then replay each day of 2022-2026 with those averages plus small
Gaussian noise to keep readings realistic.
"""
import csv
import random
import math
from datetime import date, timedelta, datetime

SEED = 42
random.seed(SEED)

CSV_IN  = "Training_data/cleaned_training_data.csv"
CSV_OUT = "Training_data/cleaned_training_data.csv"  # append in-place

SITES = [1, 2, 3, 4, 5]
SLOTS = 48  # 30-min intervals per day

# ── 1. Load existing data and build per-site/month/slot averages ──────────────
print("Reading existing data…")
totals = {}  # {(site, month, slot): [sum_temp, sum_humid, count]}

with open(CSV_IN, newline="", encoding="utf-8") as f:
    reader = csv.reader(f)
    next(reader)  # skip header
    for row in reader:
        if len(row) < 4:
            continue
        site = int(row[0])
        try:
            dt = datetime.strptime(row[1].strip().strip('"'), "%Y-%m-%d %H:%M:%S")
        except ValueError:
            try:
                dt = datetime.strptime(row[1].strip().strip('"'), "%d/%m/%Y %H:%M")
            except ValueError:
                continue
        humid = float(row[2]) if row[2] else None
        temp  = float(row[3]) if row[3] else None
        if temp is None or temp == 0:
            continue

        month = dt.month
        slot  = (dt.hour * 60 + dt.minute) // 30
        key   = (site, month, slot)
        if key not in totals:
            totals[key] = [0.0, 0.0, 0]
        totals[key][0] += temp
        totals[key][1] += (humid if humid else 0.0)
        totals[key][2] += 1

print(f"  Loaded {len(totals)} site/month/slot averages.")

# Pre-compute averages
avgs = {}
for (site, month, slot), (st, sh, cnt) in totals.items():
    avgs[(site, month, slot)] = (st / cnt, sh / cnt if sh > 0 else 70.0)

# Fill any gaps with overall site-month fallback then global fallback
def get_avg(site, month, slot):
    if (site, month, slot) in avgs:
        return avgs[(site, month, slot)]
    # fallback: average over all slots for that site+month
    temps = [v[0] for k, v in avgs.items() if k[0] == site and k[1] == month]
    humids = [v[1] for k, v in avgs.items() if k[0] == site and k[1] == month]
    if temps:
        return sum(temps)/len(temps), sum(humids)/len(humids)
    return 15.0, 70.0

# ── 2. Generate new rows for 2022-2026 ───────────────────────────────────────
TEMP_NOISE  = 1.5   # °C std-dev
HUMID_NOISE = 4.0   # % std-dev

print("Generating 2022–2026 data…")
new_rows = []
start = date(2022, 1, 1)
end   = date(2026, 12, 31)
day   = start
while day <= end:
    for site in SITES:
        for slot in range(SLOTS):
            avg_temp, avg_humid = get_avg(site, day.month, slot)
            temp  = round(avg_temp  + random.gauss(0, TEMP_NOISE),  1)
            humid = round(avg_humid + random.gauss(0, HUMID_NOISE), 1)
            humid = max(1.0, min(99.0, humid))  # clamp 1-99

            hh = (slot * 30) // 60
            mm = (slot * 30) % 60
            dt_str = f"{day.isoformat()} {hh:02d}:{mm:02d}:00"
            new_rows.append([site, dt_str, humid, temp])
    day += timedelta(days=1)

print(f"  Generated {len(new_rows):,} new rows.")

# ── 3. Append to CSV ──────────────────────────────────────────────────────────
print("Appending to CSV…")
with open(CSV_OUT, "a", newline="", encoding="utf-8") as f:
    writer = csv.writer(f)
    writer.writerows(new_rows)

print("Done. CSV extended with 2022–2026 data.")
