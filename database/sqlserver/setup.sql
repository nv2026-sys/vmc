IF DB_ID(N'emergency_dispatch') IS NULL
BEGIN
    CREATE DATABASE [emergency_dispatch];
END;
GO

IF SUSER_ID(N'vmc_app') IS NULL
BEGIN
    THROW 50000, 'Create the vmc_app SQL Server login with a deployment-managed password before running this script.', 1;
END;
GO

USE [emergency_dispatch];
GO

IF DATABASE_PRINCIPAL_ID(N'vmc_runtime') IS NULL
BEGIN
    CREATE ROLE [vmc_runtime] AUTHORIZATION [dbo];
END;
GO

GRANT SELECT, INSERT, UPDATE, DELETE ON SCHEMA::[dbo] TO [vmc_runtime];
GO

IF DATABASE_PRINCIPAL_ID(N'vmc_app') IS NULL
BEGIN
    CREATE USER [vmc_app] FOR LOGIN [vmc_app];
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.database_role_members AS membership
    INNER JOIN sys.database_principals AS role
        ON role.principal_id = membership.role_principal_id
    INNER JOIN sys.database_principals AS member
        ON member.principal_id = membership.member_principal_id
    WHERE role.name = N'vmc_runtime'
      AND member.name = N'vmc_app'
)
BEGIN
    ALTER ROLE [vmc_runtime] ADD MEMBER [vmc_app];
END;
GO
