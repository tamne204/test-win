; Script generated for Inno Setup 6.x
; VibeCode Slideshow Studio AI Production Installer

#define MyAppName "VibeCode Studio"
#define MyAppVersion "2.2.3.18"
#define MyAppPublisher "2tamne.site"
#define MyAppURL "https://www.2tamne.site/"
#define MyAppExeName "SlideshowStudio.vbs"

[Setup]
AppId={{D8249F5A-4B29-4F5E-91C7-2C1F5C6E8D1B}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}
DefaultDirName={localappdata}\Programs\VibeCode
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
LicenseFile=..\LICENSE_INFO.txt
OutputDir=..\dist
OutputBaseFilename=VibeCode_Setup_v2.2.3.18
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=lowest
CloseApplications=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
Source: "..\\app.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\camera_engine.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\diagnostic_collector.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\ffmpeg_utils.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\forced_alignment_engine.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\generate_voice.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\generate_warm_voice.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\license_manager.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\renderer_e_engine.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\renderer_g.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\subtitles_engine.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\translation_utils.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\tts_utils.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\version.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\requirements.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\start_windows.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\start_windows.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\SlideshowStudio.vbs"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\run.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\README.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\CHANGELOG.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\\templates\\*"; DestDir: "{app}\\templates"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\\static\\*"; DestDir: "{app}\\static"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\\platform\\*"; DestDir: "{app}\\platform"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\\updater\\*"; DestDir: "{app}\\updater"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\\docs\\*"; DestDir: "{app}\\docs"; Flags: ignoreversion recursesubdirs createallsubdirs

[Dirs]
Name: "{app}\projects"; Flags: uninsneveruninstall
Name: "{app}\outputs"; Flags: uninsneveruninstall
Name: "{app}\uploads"; Flags: uninsneveruninstall
Name: "{app}\diagnostics"; Flags: uninsneveruninstall

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{group}\Uninstall {#MyAppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "{cm:LaunchProgram,{#StringChange(MyAppName, '&', '&&')}}"; Flags: shellexec postinstall nowait skipifsilent
