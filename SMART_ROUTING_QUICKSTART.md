# Smart Routing - Quick Start Guide

## 🎯 What's New?

Венера V.I.P Clinic now automatically sends SMS messages with the **correct department phone number** based on which service the patient booked.

## 📱 Example

**Patient A books:** Шүдний үзлэг (Dental)
```
SMS: "Sain baina uu! Tany zahalga Venera-d {time}-d batalguajlaa. Uts: 70115090"
```

**Patient B books:** Ботокс (Cosmetic)
```
SMS: "Sain baina uu! Tany zahalga Venera-d {time}-d batalguajlaa. Uts: 70115093"
```

## ⚙️ Configuration

### Step 1: Open SMS Settings
1. Go to Admin Panel
2. Click **"💬 Мессежийн тохиргоо"** in sidebar

### Step 2: Find "Тасгуудын утасны дугаар"
Scroll down to the department section:

```
🦷 Шүдний тасаг (Dental)          → 70115090
🌿 Уламжлалт анагаа (Traditional)  → 70115091
💧 Дусал / Сувилахуй (Drip)       → 70115092
💉 Мэсийн бус гоо сайхан (Non-surgical) → 70115093
🏥 Мэс засал (Surgical)           → 70115094
```

### Step 3: Customize (Optional)
- Change phone numbers if needed
- Click **"Хадгалах"** to save

## 🔄 How It Works

When a patient books an appointment:
1. **Service name** is detected (e.g., "Шүдний үзлэг")
2. **System matches** it to a department (e.g., dental)
3. **Phone number** is selected (e.g., 70115090)
4. **SMS is sent** with that phone number

## 🚀 Services Recognized

| Service Name Contains | Department | Phone |
|----------------------|------------|-------|
| шүд, tooth, dent | Dental | 70115090 |
| уламжлалт, массаж | Traditional | 70115091 |
| дусал, сувилахуй, iv | Drip | 70115092 |
| ботокс, филлер | Non-surgical | 70115093 |
| мэс, хирург, засал | Surgical | 70115094 |

## 📞 What Patients See

### SMS Message Format
```
Sain baina uu! Tany zahalga {clinic_name}-d {date} {time}-d batalguajlaa.
Uts: {DEPARTMENT_PHONE}
```

Example:
```
Sain baina uu! Tany zahalga Venera V.I.P-d 12-28 14:00-d batalguajlaa. 
Uts: 70115091
```

## ❓ FAQ

**Q: Can I have different phones for each department?**
A: Yes! Enter different numbers in the configuration form.

**Q: What if service name doesn't match any department?**
A: Default main clinic phone (70115090) is used.

**Q: Can I add custom keywords?**
A: Not in UI yet, but contact your admin to modify the system.

**Q: Does this work for reminder SMS too?**
A: Yes! The cron job automatically uses smart routing for reminder messages 1 day before appointment.

## 📋 Testing

To test:
1. Create a booking with "Шүдний үзлэг" service
2. Check SMS - should include dental phone (70115090)
3. Create another with "Ботокс" - should include cosmetic phone (70115093)

## 📞 Support

For issues or questions:
- Contact your system administrator
- Check IMPLEMENTATION_SUMMARY.md for technical details
- See SMART_ROUTING.md for architecture

## ✅ Verification Checklist

- ✅ All 5 departments configured
- ✅ Test SMS for each department
- ✅ Cron job running (checks every 5 minutes)
- ✅ SMS templates updated with {phone} placeholder
- ✅ Database storing department phones

---

**Status:** 🟢 **LIVE AND WORKING**
