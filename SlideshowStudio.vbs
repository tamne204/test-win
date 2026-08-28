Set WshShell = CreateObject("WScript.Shell")
WshShell.Run "cmd /c start_windows.bat", 0, False
Set WshShell = Nothing
