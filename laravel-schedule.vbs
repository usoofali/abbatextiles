Set WshShell = CreateObject("WScript.Shell")
WshShell.Run chr(34) & "C:\xampp\laravel-schedule.bat" & Chr(34), 0
Set WshShell = Nothing
