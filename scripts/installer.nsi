Unicode True
!include "MUI2.nsh"
!include "FileFunc.nsh"
!include "LogicLib.nsh"

Name "2TOOLNE AutoEdit"
Caption "2TOOLNE AutoEdit Setup"
OutFile "${OUTPUT_FILE}"
InstallDir "$LOCALAPPDATA\Programs\2TOOLNE AutoEdit"
InstallDirRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "InstallLocation"
RequestExecutionLevel user

!define MUI_ABORTWARNING
ShowInstDetails show
ShowUninstDetails show

; Branding Icons
!ifndef ICON_FILE
  !define ICON_FILE "apps\capcut-v2\desktop\assets\installerIcon.ico"
!endif
!ifndef UNICON_FILE
  !define UNICON_FILE "apps\capcut-v2\desktop\assets\uninstallerIcon.ico"
!endif

!ifFileExists "${ICON_FILE}"
  !define MUI_ICON "${ICON_FILE}"
!endif
!ifFileExists "${UNICON_FILE}"
  !define MUI_UNICON "${UNICON_FILE}"
!endif

; Installer Pages
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES

; Finish Page with Run Application option (checked by default)
!define MUI_FINISHPAGE_RUN "$INSTDIR\2TOOLNE AutoEdit.exe"
!define MUI_FINISHPAGE_RUN_TEXT "Launch 2TOOLNE AutoEdit"
!insertmacro MUI_PAGE_FINISH

; Uninstaller Pages
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "English"

Section "MainSection" SEC01
  DetailPrint "Target installation directory: $INSTDIR"
  SetOutPath "$INSTDIR"
  
  DetailPrint "Extracting application payload..."
  File /r "${PACKAGE_DIR}\*.*"
  
  DetailPrint "Creating uninstaller..."
  WriteUninstaller "$INSTDIR\Uninstall 2TOOLNE AutoEdit.exe"
  
  DetailPrint "Creating Start Menu shortcuts..."
  CreateDirectory "$SMPROGRAMS\2TOOLNE AutoEdit"
  CreateShortCut "$SMPROGRAMS\2TOOLNE AutoEdit\2TOOLNE AutoEdit.lnk" "$INSTDIR\2TOOLNE AutoEdit.exe" "" "$INSTDIR\2TOOLNE AutoEdit.exe" 0
  CreateShortCut "$SMPROGRAMS\2TOOLNE AutoEdit\Uninstall 2TOOLNE AutoEdit.lnk" "$INSTDIR\Uninstall 2TOOLNE AutoEdit.exe"
  
  DetailPrint "Creating Desktop shortcut..."
  CreateShortCut "$DESKTOP\2TOOLNE AutoEdit.lnk" "$INSTDIR\2TOOLNE AutoEdit.exe" "" "$INSTDIR\2TOOLNE AutoEdit.exe" 0
  
  DetailPrint "Writing Windows Registry uninstall metadata..."
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "DisplayName" "2TOOLNE AutoEdit"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "DisplayVersion" "2.0.6"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "Publisher" "2TOOLNE Team"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "DisplayIcon" "$INSTDIR\2TOOLNE AutoEdit.exe,0"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "UninstallString" '"$INSTDIR\Uninstall 2TOOLNE AutoEdit.exe"'
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "QuietUninstallString" '"$INSTDIR\Uninstall 2TOOLNE AutoEdit.exe" /S'
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "InstallLocation" "$INSTDIR"
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "NoModify" 1
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "NoRepair" 1
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit" "EstimatedSize" 365000
  
  DetailPrint "2TOOLNE AutoEdit v2.0.6 installation completed successfully."
SectionEnd

Section "Uninstall"
  DetailPrint "Removing Desktop and Start Menu shortcuts..."
  Delete "$DESKTOP\2TOOLNE AutoEdit.lnk"
  Delete "$SMPROGRAMS\2TOOLNE AutoEdit\2TOOLNE AutoEdit.lnk"
  Delete "$SMPROGRAMS\2TOOLNE AutoEdit\Uninstall 2TOOLNE AutoEdit.lnk"
  RMDir "$SMPROGRAMS\2TOOLNE AutoEdit"
  
  DetailPrint "Removing Windows Registry entries..."
  DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\2TOOLNE AutoEdit"
  
  DetailPrint "Removing application files from $INSTDIR..."
  SetOutPath "$TEMP"
  RMDir /r "$INSTDIR"
  
  DetailPrint "Uninstallation complete."
SectionEnd
