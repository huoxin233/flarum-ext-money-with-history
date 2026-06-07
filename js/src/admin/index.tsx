import app from 'flarum/admin/app';

app.initializers.add('huoxin/money-with-history', () => {
  app.extensionData
    .for('huoxin-money-with-history')
    .registerSetting({
      setting: 'huoxin-money-with-history.money_name',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.money_name'),
      type: 'text',
    })
    .registerSetting(function (this: any) {
      return (
        <div className="Form-group">
          <label>{app.translator.trans('huoxin-money-with-history.admin.settings.post_reward_amount')}</label>
          <input type="number" className="FormControl" step="any" bidi={this.setting('huoxin-money-with-history.post_reward_amount')} />
        </div>
      );
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.min_post_length',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.min_post_length'),
      type: 'number',
    })
    .registerSetting(function (this: any) {
      return (
        <div className="Form-group">
          <label>{app.translator.trans('huoxin-money-with-history.admin.settings.discussion_reward_amount')}</label>
          <input type="number" className="FormControl" step="any" bidi={this.setting('huoxin-money-with-history.discussion_reward_amount')} />
        </div>
      );
    })
    .registerSetting(function (this: any) {
      return (
        <div className="Form-group">
          <label>{app.translator.trans('huoxin-money-with-history.admin.settings.like_reward_amount')}</label>
          <div className="helpText">{app.translator.trans('huoxin-money-with-history.admin.settings.help_extension_likes')}</div>
          <input type="number" className="FormControl" step="any" bidi={this.setting('huoxin-money-with-history.like_reward_amount')} />
        </div>
      );
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.remove_money_trigger',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.remove_money_trigger'),
      type: 'select',
      options: {
        '0': app.translator.trans('huoxin-money-with-history.admin.remove_money_trigger.0'),
        '1': app.translator.trans('huoxin-money-with-history.admin.remove_money_trigger.1'),
        '2': app.translator.trans('huoxin-money-with-history.admin.remove_money_trigger.2'),
      },
      default: '1',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.reward_self_like',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.reward_self_like'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.cascade_money_removal',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.cascade_money_removal'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.reward_private_discussion',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.reward_private_discussion'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.exclude_mentions_from_length',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.exclude_mentions_from_length'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: 'huoxin-money-with-history.hide_zero_balances',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.hide_zero_balances'),
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
