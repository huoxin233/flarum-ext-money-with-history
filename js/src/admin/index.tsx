import app from 'flarum/admin/app';

app.initializers.add('huoxin/money-with-history', () => {
  app.extensionData
    .for('huoxin-money-with-history')
    .registerSetting({
      setting: 'huoxin-money-with-history.moneyname',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.moneyname'),
      type: 'text',
    })
    .registerSetting(function (this: any) {
      return (
        <div className="Form-group">
          <label>{app.translator.trans('huoxin-money-with-history.admin.settings.moneyforpost')}</label>
          <input type="number" className="FormControl" step="any" bidi={this.setting('huoxin-money-with-history.moneyforpost')} />
        </div>
      );
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.postminimumlength',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.postminimumlength'),
      type: 'number',
    })
    .registerSetting(function (this: any) {
      return (
        <div className="Form-group">
          <label>{app.translator.trans('huoxin-money-with-history.admin.settings.moneyfordiscussion')}</label>
          <input type="number" className="FormControl" step="any" bidi={this.setting('huoxin-money-with-history.moneyfordiscussion')} />
        </div>
      );
    })
    .registerSetting(function (this: any) {
      return (
        <div className="Form-group">
          <label>{app.translator.trans('huoxin-money-with-history.admin.settings.moneyforlike')}</label>
          <div className="helpText">{app.translator.trans('huoxin-money-with-history.admin.settings.helpextensionlikes')}</div>
          <input type="number" className="FormControl" step="any" bidi={this.setting('huoxin-money-with-history.moneyforlike')} />
        </div>
      );
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.autoremove',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.autoremove'),
      type: 'select',
      options: {
        '0': app.translator.trans('huoxin-money-with-history.admin.autoremove.0'),
        '1': app.translator.trans('huoxin-money-with-history.admin.autoremove.1'),
        '2': app.translator.trans('huoxin-money-with-history.admin.autoremove.2'),
      },
      default: '1',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.cascaderemove',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.cascaderemove'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.ignorenotifyingusers',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.ignore_notifying_users'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.noshowzero',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.noshowzero'),
      type: 'checkbox',
    })
    .registerPermission(
      {
        icon: 'fas fa-money-bill',
        label: app.translator.trans('huoxin-money-with-history.admin.permissions.edit_money_label'),
        permission: 'user.edit_money',
      },
      'moderate'
    )
    .registerPermission(
      {
        icon: 'far fa-eye',
        label: app.translator.trans('huoxin-money-with-history.admin.permissions.disable_money_label'),
        permission: 'discussion.money.disable_money',
      },
      'start'
    )
    .registerPermission(
      {
        icon: 'fas fa-id-card',
        label: app.translator.trans('huoxin-money-with-history.admin.permissions.query_others_history'),
        permission: 'money-history.queryOthersMoneyHistory',
        allowGuest: true,
      },
      'view'
    );
});
