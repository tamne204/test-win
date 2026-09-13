Unicode True
!include "MUI2.nsh"
!include "FileFunc.nsh"

Name "2TOOLNE AutoEdit"
OutFile "${OUTPUT_FILE}"
InstallDir "$LOCALAPPDATA\Programs\2TOOLNE AutoEdit"
RequestExecutionLevel user

!define MUI_ABORTWARNING

!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "English"

Section "MainSection" SEC01
  SetOutPath "$INSTDIR"
  File /r "${PACKAGE_DIR}\*.*"
  WriteUninstaller "$INSTDIR\Uninstall 2TOOLNE AutoEdit.exe"
SectionEnd

Section "Uninstall"
  RMDir /r "$INSTDIR"
SectionEnd
