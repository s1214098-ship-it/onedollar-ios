; 峰連遠端 Windows 安裝程式（Inno Setup 6）
#define MyAppName "峰連遠端"
#define MyAppNameEn "PeakLink"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "PeakLink"
#define MyAppExeName "PeakLink.exe"

[Setup]
AppId={{A4E8C1D2-9F3B-4A71-8C55-7E2B91D04F18}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={localappdata}\Programs\PeakLink
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=PeakLink-Setup-{#MyAppVersion}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
SetupIconFile=peaklink.ico
UninstallDisplayIcon={app}\{#MyAppExeName}
ShowLanguageDialog=auto
LanguageDetectionMethod=uilanguage
InfoBeforeFile=install-notes.txt
LicenseFile=license.txt

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "建立桌面捷徑"; GroupDescription: "捷徑："; Flags: unchecked

[Files]
Source: "..\dist\PeakLink\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\README.md"; DestDir: "{app}"; Flags: isreadme

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{group}\解除安裝 {#MyAppName}"; Filename: "{uninstallexe}"
Name: "{userdesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "立即啟動 {#MyAppName}"; Flags: nowait postinstall skipifsilent
